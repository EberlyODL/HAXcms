<?php
class Operations {
  public $params;
  public $rawParams;
  /**
   * META: options request
   */
  public function options() {
    return get_class_methods($this);
  }
  /**
   * HAXCMS SITE OPERATIONS
   */
  /**
   * save manifest
   */
  public function saveManifest() {
    // load the site from name
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    // standard form submit
    if (isset($this->rawParams['manifest']->title)) {
        $site->manifest->title = filter_var(
            $this->rawParams['manifest']->title,
            FILTER_SANITIZE_STRING
        );
        $site->manifest->description = filter_var(
            $this->rawParams['manifest']->description,
            FILTER_SANITIZE_STRING
        );
        $site->manifest->metadata->image = filter_var(
            $this->rawParams['manifest']->image,
            FILTER_SANITIZE_STRING
        );
        if (isset($this->rawParams['manifest']->hexCode)) {
            $site->manifest->metadata->hexCode = filter_var(
                $this->rawParams['manifest']->hexCode,
                FILTER_SANITIZE_STRING
            );
        }
        if (isset($this->rawParams['manifest']->cssVariable)) {
            $site->manifest->metadata->cssVariable = filter_var(
            $this->rawParams['manifest']->cssVariable,
            FILTER_SANITIZE_STRING
            );
        }
        // update these parts of the manifest to match POST
        if (isset($this->rawParams['manifest']->icon)) {
            $site->manifest->metadata->icon = filter_var(
                $this->rawParams['manifest']->icon,
                FILTER_SANITIZE_STRING
            );
        }
        if (isset($this->rawParams['manifest']->domain)) {
            $domain = filter_var(
                $this->rawParams['manifest']->domain,
                FILTER_SANITIZE_STRING
            );
            // support updating the domain CNAME value
            if ($site->manifest->metadata->domain != $domain) {
                $site->manifest->metadata->domain = $domain;
                @file_put_contents(
                    $site->directory .
                        '/' .
                        $site->manifest->siteName .
                        '/CNAME',
                    $domain
                );
            }
        }
        // look for a match so we can set the correct data
        foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $theme) {
            if (
                filter_var($this->rawParams['manifest']->theme, FILTER_SANITIZE_STRING) ==
                $key
            ) {
                $site->manifest->metadata->theme = $theme;
            }
        }
    }
    // advanced form submitted
    if (isset($this->rawParams['manifest']->license)) {
        $site->manifest->license = filter_var(
            $this->rawParams['manifest']->license,
            FILTER_SANITIZE_STRING
        );
        $site->manifest->author = filter_var(
            $this->rawParams['manifest']->author,
            FILTER_SANITIZE_STRING
        );
    }
    if (isset($this->rawParams['manifest']->pathauto)) {
        $site->manifest->metadata->pathauto = filter_var(
        $this->rawParams['manifest']->pathauto,
        FILTER_VALIDATE_BOOLEAN
        );
    }
    // more importantly, this is where the field UI stuff is...
    if (isset($this->rawParams['manifest']->fields)) {
        $fields = new stdClass();
        $fields->configure = array();
        $fields->advanced = array();
        // establish a fields block
        $site->manifest->metadata->fields = new stdClass();
        $site->manifest->metadata->fields->configure = array();
        $site->manifest->metadata->fields->advanced = array();
        foreach ($this->rawParams['manifest']->fields as $key => $field) {
            // ensure formgroup isset, shouldn't be possible..
            if (!isset($field->formgroup)) {
                $field->formgroup = 'configure';
            }
            $fieldgroup = $field->formgroup;
            unset($field->formgroup);
            // another sanity check
            if ($fieldgroup == 'configure' || $fieldgroup == 'advanced') {
                array_push($fields->{$fieldgroup}, $field);
            }
        }
        if (count($fields->configure) > 0) {
            $site->manifest->metadata->fields->configure = $fields->configure;
        }
        if (count($fields->advanced) > 0) {
            $site->manifest->metadata->fields->advanced = $fields->advanced;
        }
    }
    $site->manifest->metadata->updated = time();
    $site->manifest->save(false);
    // now work on HAXCMS layer to match the saved / sanitized data
    $item = $site->manifest;
    // remove items list as we only need the item itself not the nesting
    unset($item->items);
    $GLOBALS['HAXCMS']->outlineSchema->updateItem($item, true);
    $site->gitCommit('Manifest updated');
    // check git remote if it came across as a possible setting
    if (isset($this->rawParams['manifest']->git)) {
      if (
        filter_var($this->rawParams['manifest']->git->url, FILTER_SANITIZE_STRING) &&
        (!isset($site->manifest->metadata->git->url) ||
          $site->manifest->metadata->git->url !=
            filter_var(
              $this->rawParams['manifest']->git->url,
              FILTER_SANITIZE_STRING
            ))
      ) {
        $site->gitSetRemote(
            filter_var($this->rawParams['manifest']->git->url, FILTER_SANITIZE_STRING)
        );
      }
    }
    return $site->manifest;
  }
  /**
   * save outline
   */
  public function saveOutline() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    $original = $site->manifest->items;
    $items = $this->rawParams['items'];
    $itemMap = array();
    // items from the POST
    foreach ($items as $key => $item) {
      // get a fake item
      if (!($page = $site->loadNode($item->id))) {
          $page = $GLOBALS['HAXCMS']->outlineSchema->newItem();
          $itemMap[$item->id] = $page->id;
      } else {
          $page->id = $item->id;
      }
      // set a crappy default title
      $page->title = $item->title;
      if ($item->parent == null) {
          $page->parent = null;
          $page->indent = 0;
      } else {
          // check the item map as backend dictates unique ID
          if (isset($itemMap[$item->parent])) {
              $page->parent = $itemMap[$item->parent];
          } else {
              // set to the parent id
              $page->parent = $item->parent;
          }
          // move it one indentation below the parent; this can be changed later if desired
          $page->indent = $item->indent;
      }
      if (isset($item->order)) {
          $page->order = $item->order;
      } else {
          $page->order = $key;
      }
      // keep location if we get one already
      if (isset($item->location) && $item->location != '') {
          // force location to be in the right place
          $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($item->location);
          $page->location = 'pages/' . $cleanTitle . '/index.html';
      } else {
          $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($page->title);
          // generate a logical page location
          $page->location = 'pages/' . $cleanTitle . '/index.html';
      }
      // verify this exists, front end could have set what they wanted
      // or it could have just been renamed
      $siteDirectory =
          $site->directory . '/' . $site->manifest->metadata->siteName;
      // if it doesn't exist currently make sure the name is unique
      if (!$site->loadNode($page->id)) {
          // ensure this location doesn't exist already
          $tmpTitle = $site->getUniqueLocationName($cleanTitle, $page);
          $page->location = 'pages/' . $tmpTitle . '/index.html';
          $site->recurseCopy(
              HAXCMS_ROOT . '/system/boilerplate/page',
              $siteDirectory . '/pages/' . $tmpTitle
          );
      }
      // this would imply existing item, lets see if it moved or needs moved
      else {
          $moved = false;
          foreach ($original as $key => $tmpItem) {
              // see if this is something moving as opposed to brand new
              if (
                  $tmpItem->id == $page->id &&
                  $tmpItem->location != ''
              ) {
                  // core support for automatically managing paths to make them nice
                  if (isset($site->manifest->metadata->pathauto) && $site->manifest->metadata->pathauto) {
                      $moved = true;
                      $new = 'pages/' . $site->getUniqueLocationName($GLOBALS['HAXCMS']->cleanTitle($page->title), $page) . '/index.html';
                      $site->renamePageLocation(
                          $page->location,
                          $new
                      );
                      $page->location = $new;
                  }
                  else if ($tmpItem->location != $page->location) {
                      $moved = true;
                      // @todo might want something to rebuild the path based on new parents
                      $site->renamePageLocation(
                          $tmpItem->location,
                          $page->location
                      );
                  }
              }
          }
          // it wasn't moved and it doesn't exist... let's fix that
          // this is beyond an edge case
          if (
              !$moved &&
              !file_exists($siteDirectory . '/' . $page->location)
          ) {
              // ensure this location doesn't exist already
              $tmpTitle = $site->getUniqueLocationName($cleanTitle, $page);
              $page->location = 'pages/' . $tmpTitle . '/index.html';
              $site->recurseCopy(
                  HAXCMS_ROOT . '/system/boilerplate/page',
                  $siteDirectory . '/pages/' . $tmpTitle
              );
          }
      }
      // check for any metadata keys that did come over
      foreach ($item->metadata as $key => $value) {
          $page->metadata->{$key} = $value;
      }
      // safety check for new things
      if (!isset($page->metadata->created)) {
          $page->metadata->created = time();
      }
      // always update at this time
      $page->metadata->updated = time();
      if ($site->loadNode($page->id)) {
          $site->updateNode($page);
      } else {
          $site->manifest->addItem($page);
      }
    }
    $site->manifest->metadata->updated = time();
    $site->manifest->save();
    $site->gitCommit('Outline updated in bulk');
    return $site->manifest->items;
  }
  /**
   * create node
   */
  public function createNode() {
    $site = $GLOBALS['HAXCMS']->loadSite(strtolower($this->params['siteName']));
    // get a new item prototype
    $item = $GLOBALS['HAXCMS']->outlineSchema->newItem();
    // set the title
    $item->title = str_replace("\n", '', $this->params['title']);
    if (isset($this->params['id']) && $this->params['id'] != '') {
        $item->id = $this->params['id'];
    }
    if (isset($this->params['location']) && $this->params['location'] != '') {
        $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($this->params['location']);
    } else {
        $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($item->title);
    }
    // ensure this location doesn't exist already
    $item->location =
        'pages/' . $site->getUniqueLocationName($cleanTitle) . '/index.html';

    if (isset($this->params['indent']) && $this->params['indent'] != '') {
        $item->indent = $this->params['indent'];
    }
    if (isset($this->params['order']) && $this->params['order'] != '') {
        $item->order = $this->params['order'];
    }
    if (isset($this->params['parent']) && $this->params['parent'] != '') {
        $item->parent = $this->params['parent'];
    } else {
        $item->parent = null;
    }
    if (isset($this->params['description']) && $this->params['description'] != '') {
        $item->description = str_replace("\n", '', $this->params['description']);
    }
    if (isset($this->params['order']) && $this->params['metadata'] != '') {
        $item->metadata = $this->params['metadata'];
    }
    $item->metadata->created = time();
    $item->metadata->updated = time();
    // add the item back into the outline schema
    // @todo fix logic here to actually create the page based on 1 call
    // this logic should be cleaned up in addPage to allow for
    // passing in arguments
    $site->recurseCopy(
        HAXCMS_ROOT . '/system/boilerplate/page',
        $site->directory .
            '/' .
            $site->manifest->metadata->siteName .
            '/' .
            str_replace('/index.html', '', $item->location)
    );
    $site->manifest->addItem($item);
    $site->manifest->save();
    $site->gitCommit('Page added:' . $item->title . ' (' . $item->id . ')');
    return $item;
  }
  /**
   * save node
   */
  public function saveNode() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    $schema = array();
    if (isset($this->rawParams['body'])) {
      $body = $this->rawParams['body'];
      // we ship the schema with the body
      if (isset($this->rawParams['schema'])) {
        $schema = $this->rawParams['schema'];
      }
    }
    if (isset($this->rawParams['details'])) {
      $details = $this->rawParams['details'];
    }
    // update the page's content, using manifest to find it
    // this ensures that writing is always to what the file system
    // determines to be the correct page
    if ($page = $site->loadNode($this->params['nodeId'])) {
      // convert web location for loading into file location for writing
      if (isset($body)) {
        $bytes = $page->writeLocation(
          $body,
          HAXCMS_ROOT .
          '/' .
          $GLOBALS['HAXCMS']->sitesDirectory .
          '/' .
          $site->name .
          '/'
        );
        if ($bytes === false) {
          return array(
            '__failed' => array(
              'status' => 500,
              'message' => 'failed to write',
            )
          );
        } else {
            // sanity check
            if (!isset($page->metadata)) {
              $page->metadata = new stdClass();
            }
            // update the updated timestamp
            $page->metadata->updated = time();
            // auto generate a text only description from first 200 chars
            $clean = strip_tags($body);
            $page->description = str_replace(
                "\n",
                '',
                substr($clean, 0, 200)
            );
            $readtime = round(str_word_count($clean) / 200);
            // account for uber small body
            if ($readtime == 0) {
              $readtime = 1;
            }
            $page->metadata->readtime = $readtime;
            // assemble other relevent content detail by skimming it off
            $contentDetails = new stdClass();
            $contentDetails->headings = 0;
            $contentDetails->paragraphs = 0;
            $contentDetails->schema = array();
            $contentDetails->tags = array();
            $contentDetails->elements = count($schema);
            // pull schema apart and store the relevent pieces
            foreach ($schema as $element) {
              switch($element->tag) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $contentDetails->headings++;
                break;
                case 'p':
                    $contentDetails->paragraphs++;
                break;
              }
              if (!isset($contentDetails->tags[$element->tag])) {
                  $contentDetails->tags[$element->tag] = 0;
              }
              $contentDetails->tags[$element->tag]++;
              $newItem = new stdClass();
              $hasSchema = false;
              if (isset($element->properties->property)) {
                $hasSchema = true;
                $newItem->property = $element->properties->property;
              }
              if (isset($element->properties->typeof)) {
                $hasSchema = true;
                $newItem->typeof = $element->properties->typeof;
              }
              if (isset($element->properties->resource)) {
                $hasSchema = true;
                $newItem->resource = $element->properties->resource;
              }
              if (isset($element->properties->prefix)) {
                $hasSchema = true;
                $newItem->prefix = $element->properties->prefix;
              }
              if (isset($element->properties->vocab)) {
                $hasSchema = true;
                $newItem->vocab = $element->properties->vocab;
              }
              if ($hasSchema) {
                $contentDetails->schema[] = $newItem;
              }
            }
            $page->metadata->contentDetails = $contentDetails;
            $site->updateNode($page);
            $site->gitCommit(
              'Page updated: ' . $page->title . ' (' . $page->id . ')'
            );
            return $bytes;
        }
      } elseif (isset($details)) {
        // update the updated timestamp
        $page->metadata->updated = time();
        foreach ($details as $key => $value) {
            // sanitize both sides
            $key = filter_var($key, FILTER_SANITIZE_STRING);
            switch ($key) {
                case 'location':
                    // check on name
                    $value = filter_var($value, FILTER_SANITIZE_STRING);
                    $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($value);
                    if (isset($site->manifest->metadata->pathauto) && $site->manifest->metadata->pathauto) {
                        $new = 'pages/' . $site->getUniqueLocationName($GLOBALS['HAXCMS']->cleanTitle(filter_var($details->title, FILTER_SANITIZE_STRING)), $page) . '/index.html';
                        $site->renamePageLocation(
                            $page->location,
                            $new
                        );
                        $page->location = $new;
                    }
                    else if (
                        $cleanTitle !=
                        str_replace(
                            'pages/',
                            '',
                            str_replace('/index.html', '', $page->location)
                        )
                    ) {
                        $tmpTitle = $site->getUniqueLocationName(
                            $cleanTitle, $page
                        );
                        $location = 'pages/' . $tmpTitle . '/index.html';
                        // move the folder
                        $site->renamePageLocation(
                            $page->location,
                            $location
                        );
                        $page->location = $location;
                    }
                    break;
                case 'title':
                case 'description':
                    $value = filter_var($value, FILTER_SANITIZE_STRING);
                    $page->{$key} = $value;
                    break;
                case 'created':
                    $value = filter_var($value, FILTER_VALIDATE_INT);
                    $page->metadata->created = $value;
                    break;
                case 'theme':
                    $themes = $GLOBALS['HAXCMS']->getThemes();
                    $value = filter_var($value, FILTER_SANITIZE_STRING);
                    if (isset($themes->{$value})) {
                        $page->metadata->theme = $themes->{$value};
                        $page->metadata->theme->key = $value;
                    }
                    break;
                default:
                    // ensure ID is never changed
                    if ($key != 'id') {
                        // support for saving fields
                        if (!isset($page->metadata->fields)) {
                            $page->metadata->fields = new stdClass();
                        }
                        switch (gettype($value)) {
                            case 'array':
                            case 'object':
                                $page->metadata->fields->{$key} = new stdClass();
                                foreach ($value as $key2 => $val) {
                                    $page->metadata->fields->{$key}->{$key2} = new stdClass();
                                    $key2 = filter_var(
                                        $key2,
                                        FILTER_VALIDATE_INT
                                    );
                                    foreach ($val as $key3 => $deepVal) {
                                        $key3 = filter_var(
                                            $key3,
                                            FILTER_SANITIZE_STRING
                                        );
                                        $deepVal = filter_var(
                                            $deepVal,
                                            FILTER_SANITIZE_STRING
                                        );
                                        $page->metadata->fields->{$key}->{$key2}->{$key3} = $deepVal;
                                    }
                                }
                                break;
                            case 'integer':
                            case 'double':
                                $value = filter_var(
                                    $value,
                                    FILTER_VALIDATE_INT
                                );
                                $page->metadata->fields->{$key} = $value;
                                break;
                            default:
                                $value = filter_var(
                                    $value,
                                    FILTER_SANITIZE_STRING
                                );
                                $page->metadata->fields->{$key} = $value;
                                break;
                        }
                    }
                    break;
            }
        }
        $site->updateNode($page);
        $site->gitCommit(
            'Page details updated: ' . $page->title . ' (' . $page->id . ')'
        );
        return $page;
      }
    }
  }
  /**
   * delete node
   */
  public function deleteNode() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    // update the page's content, using manifest to find it
    // this ensures that writing is always to what the file system
    // determines to be the correct page
    if ($page = $site->loadNode($this->params['nodeId'])) {
        if ($site->deleteNode($page) === false) {
          return array(
            '__failed' => array(
              'status' => 500,
              'message' => 'failed to delete',
            )
          );
        } else {
          $site->gitCommit(
            'Page deleted: ' . $page->title . ' (' . $page->id . ')'
          );
          return $page;
        }
        exit();
    } else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'failed to load',
        )
      );
    }
  }
  /**
   * Update site alternate formats
   */
  public function siteUpdateAlternateFormats() {
    $format = NULL;
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    if (isset($this->params['format'])) {
      $format = $this->params['format'];
    }
    $site->updateAlternateFormats($format);
  }
  /**
   * Revert site commit
   */
  public function revertCommit() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    // this will revert the top commit
    $site->gitRevert();
    return TRUE;
  }
  /**
   * fields associated with node
   */
  public function getNodeFields() {
    if ($GLOBALS['HAXCMS']->validateRequestToken(null, 'fields')) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
      if ($page = $site->loadNode($this->params['nodeId'])) {
        $schema = $site->loadNodeFieldSchema($page);
        return $schema;
      }
    } else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }

  /**
   * 
   * HAX EDITOR CALLBACKS
   * 
   */
  /**
   * Generate the AppStore spec for HAX editor directions
   */
  public function generateAppStore() {
    // test if this is a valid user login with this specialty token that HAX looks for
    if (
      isset($this->params['app-store-token']) &&
      $GLOBALS['HAXCMS']->validateRequestToken($this->params['app-store-token'], 'appstore')
    ) {
      $haxService = new HAXService();
      $apikeys = array();
      $baseApps = $haxService->baseSupportedApps();
      foreach ($baseApps as $key => $app) {
        if (
          isset($GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key}) &&
          $GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key} != ''
        ) {
          $apikeys[$key] = $GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key};
        }
      }
      $appStore = $haxService->loadBaseAppStore($apikeys);
      // pull in the core one we supply, though only upload works currently
      $tmp = json_decode($GLOBALS['HAXCMS']->siteConnectionJSON());
      array_push($appStore, $tmp);
      if (isset($GLOBALS['HAXCMS']->config->appStore->stax)) {
          $staxList = $GLOBALS['HAXCMS']->config->appStore->stax;
      } else {
          $staxList = $haxService->loadBaseStax();
      }
      if (isset($GLOBALS['HAXCMS']->config->appStore->blox)) {
          $bloxList = $GLOBALS['HAXCMS']->config->appStore->blox;
      } else {
          $bloxList = $haxService->loadBaseBlox();
      }
      if (isset($GLOBALS['HAXCMS']->config->appStore->autoloader)) {
          $autoloaderList = $GLOBALS['HAXCMS']->config->appStore->autoloader;
      } else {
          $autoloaderList = json_decode('
        [
          "video-player",
          "meme-maker",
          "lrn-aside",
          "grid-plate",
          "tab-list",
          "magazine-cover",
          "image-compare-slider",
          "license-element",
          "self-check",
          "multiple-choice",
          "oer-schema",
          "hero-banner",
          "task-list",
          "lrn-table",
          "media-image",
          "lrndesign-blockquote",
          "a11y-gif-player",
          "paper-audio-player",
          "wikipedia-query",
          "lrn-vocab",
          "full-width-image",
          "person-testimonial",
          "citation-element",
          "stop-note",
          "place-holder",
          "lrn-math",
          "q-r",
          "lrndesign-gallery",
          "lrndesign-timeline"
        ]
        ');
      }
      return array(
          'status' => 200,
          'apps' => $appStore,
          'stax' => $staxList,
          'blox' => $bloxList,
          'autoloader' => $autoloaderList
      );
    }
  }
  /**
   * load files
   */
  public function loadFiles() {
    // @todo make this load the files out of the JSON outline schema and only return them
    return array();
  }
  /**
   * save file from editor upload
   */
  public function saveFile() {
    // @todo might want to scrub prior to this level but not sure
    if (isset($_FILES['file-upload'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
      // update the page's content, using manifest to find it
      // this ensures that writing is always to what the file system
      // determines to be the correct page
      $page = $site->loadNode($this->params['nodeId']);
      $upload = $_FILES['file-upload'];
      $file = new HAXCMSFile();
      $fileResult = $file->save($upload, $site, $page);
      if ($fileResult['status'] == 500) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'failed to write',
          )
        );
      }
      $site->gitCommit('File added: ' . $upload['name']);
      return $fileResult;
    }
  }
  /**
   * 
   * HAXCMS CORE SETTINGS AND FIELDS FOR SITES AND INTERNALS
   * 
   */
  /**
   * get site fields
   */
  public function getSiteFields() {
    if ($GLOBALS['HAXCMS']->validateRequestToken(null, 'fields')) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
      $schema = $site->loadSiteFieldSchema();
      return $schema;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
  /**
   * List available themes
   */
  public function getThemes() {
    if ($GLOBALS['HAXCMS']->validateRequestToken()) {
      $response = new stdClass();
      $response->themes = $GLOBALS['HAXCMS']->getThemes();
      return $response;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
  /**
   * 
   * SITE LISTING CALLBACKS
   * 
   */
  /**
   * List sites on the overview page
   */
  public function listSites() {
    // top level fake JOS
    $return = array(
      "id" => "123-123-123-123",
      "title" => "My sites",
      "author" => "me",
      "description" => "All of my micro sites I know and love.",
      "license" => "by-sa",
      "metadata" => array(),
      "items" => array()
    );
    // loop through files directory so we can cache those things too
    if ($handle = opendir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory)) {
      while (false !== ($item = readdir($handle))) {
        if ($item != "." && $item != ".." && is_dir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item) && file_exists(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json')) {
          $json = file_get_contents(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json');
          $site = json_decode($json);
          $site->location = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/';
          $site->metadata->pageCount = count($site->items);
          unset($site->items);
          $return['items'][] = $site;
        }
      }
      closedir($handle);
    }
    return $return;
  }
  /**
   * Create site
   */
  public function createSite() {
    if ($GLOBALS['HAXCMS']->validateRequestToken()) {
      $domain = null;
      // woohoo we can edit this thing!
      if (isset($this->params['domain'])) {
          $domain = $this->params['domain'];
      }
      // sanitize siteName
      $siteName = $GLOBALS['HAXCMS']->generateMachineName($this->params['siteName']);
      $site = $GLOBALS['HAXCMS']->loadSite(
          strtolower($siteName),
          true,
          $domain
      );
      // now get a new item to reference this into the top level sites listing
      $schema = $GLOBALS['HAXCMS']->outlineSchema->newItem();
      $schema->id = $site->manifest->id;
      $schema->title = $siteName;
      $schema->location =
          $GLOBALS['HAXCMS']->basePath .
          $GLOBALS['HAXCMS']->sitesDirectory .
          '/' .
          $site->manifest->metadata->siteName .
          '/index.html';
      $schema->metadata->siteName = $site->manifest->metadata->siteName;
      if (isset($this->params['theme'])) {
        $theme = $this->params['theme'];
      }
      else {
        $theme = HAXCMS_DEFAULT_THEME;
      }
      // look for a match so we can set the correct data
      foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $themeObj) {
          if ($theme == $key) {
              $schema->metadata->theme = $themeObj;
          }
      }
      // description for an overview if desired
      if (isset($this->params['description'])) {
          $schema->description = $this->params['description'];
      }
      // background image / banner
      if (isset($this->params['image'])) {
          $schema->metadata->image = $this->params['image'];
      }
      else {
        $schema->metadata->image = 'assets/banner.jpg';
      }
      // icon to express the concept / visually identify site
      if (isset($this->params['icon'])) {
          $schema->metadata->icon = $this->params['icon'];
      }
      // slightly style the site based on css vars and hexcode
      if (isset($this->params['hexCode'])) {
          $hex = $this->params['hexCode'];
      } else {
          $hex = '#aeff00';
      }
      $schema->metadata->hexCode = $hex;
      if (isset($this->params['cssVariable'])) {
          $cssvar = $this->params['cssVariable'];
      } else {
          $cssvar = '--simple-colors-default-theme-light-blue-7';
      }
      $schema->metadata->created = time();
      $schema->metadata->updated = time();
      $schema->metadata->cssVariable = $cssvar;
      // check for publishing settings being set globally in HAXCMS
      // this would allow them to fork off to different locations down stream
      $schema->metadata->publishing = new stdClass();
      if (isset($GLOBALS['HAXCMS']->config->publishing->git->vendor)) {
          $schema->metadata->publishing->git =
              $GLOBALS['HAXCMS']->config->publishing->git;
          unset($schema->metadata->publishing->git->keySet);
          unset($schema->metadata->publishing->git->email);
          unset($schema->metadata->publishing->git->user);
      }
      // mirror the metadata information into the site's info
      // this means that this info is available to the full site listing
      // as well as this individual site. saves on performance / calls
      // later on if we only need to hit 1 file each time to get all the
      // data we need.
      foreach ($schema->metadata as $key => $value) {
          $site->manifest->metadata->{$key} = $value;
      }
      // @todo support injecting this with out things via PHP
      $site->manifest->metadata->dynamicElementLoader = $GLOBALS['HAXCMS']->config->dynamicElementLoader;
      $site->manifest->description = $schema->description;
      // save the outline into the new site
      $site->manifest->save(false);
      // main site schema doesn't care about publishing settings
      unset($schema->metadata->publishing);
      $git = new Git();
      $repo = $git->open(
          $site->directory . '/' . $site->manifest->metadata->siteName
      );
      $repo->add('.');
      $site->gitCommit(
          'A new journey begins: ' .
              $site->manifest->title .
              ' (' .
              $site->manifest->id .
              ')'
      );
      // make a branch but dont use it
      if (isset($site->manifest->metadata->publishing->git->branch)) {
          $repo->create_branch(
              $site->manifest->metadata->publishing->git->branch
          );
      }
      return $schema;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
  /**
   * Get configuration related to HAXcms itself
   */
  public function getConfig() {
    $response = new stdClass();
    $response->schema = $GLOBALS['HAXCMS']->getConfigSchema();
    $response->values = $GLOBALS['HAXCMS']->config;
    foreach ($response->values->appStore as $key => $val) {
      if ($key !== 'apiKeys') {
        unset($response->values->appStore->{$key});
      }
    }
    return $response;
  }
  /**
   * Set system configuration
   */
  public function setConfig() {
    if ($GLOBALS['HAXCMS']->validateRequestToken()) {
      $values = $this->rawParams['values'];
      $val = new stdClass();
      if (isset($values->apis) && isset($values->appStore->apiKeys)) {
        $val->apis = $values->apis;
      }
      if (isset($values->publishing)) {
        $val->publishing = $values->publishing;
      }
      $response = $GLOBALS['HAXCMS']->setConfig($val);
      return $response;
    } else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'failed to validate request token',
        )
      );
    }
  }
  /**
   * publish site
   */
  public function publishSite() {
    // ensure we have something we can load and ship back out the door
    if ($site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName'])) {
        // local publishing options, then defer to system, then make some up...
        if (isset($site->manifest->metadata->publishing->git)) {
            $gitSettings = $site->manifest->metadata->publishing->git;
        } elseif (isset($GLOBALS['HAXCMS']->config->publishing->git)) {
            $gitSettings = $GLOBALS['HAXCMS']->config->publishing->git;
        } else {
            $gitSettings = new stdClass();
            $gitSettings->vendor = 'github';
            $gitSettings->branch = 'gh-pages';
            $gitSettings->url = '';
        }
        if (isset($gitSettings)) {
            $git = new Git();
            $siteDirectoryPath =
                $site->directory . '/' . $site->manifest->metadata->siteName;
            $repo = $git->open($siteDirectoryPath, true);
            // ensure we're on master and everything is added
            $repo->checkout('master');
            // Try to build a reasonable "domain" value
            if (
                isset($gitSettings->url) &&
                $gitSettings->url != '' &&
                $gitSettings->url != false &&
                $gitSettings->url !=
                    '/' . $site->manifest->metadata->siteName . '.git'
            ) {
                $domain = $gitSettings->url;
                if (
                    isset($site->manifest->metadata->domain) &&
                    $site->manifest->metadata->domain != ''
                ) {
                    $domain = $site->manifest->metadata->domain;
                } else {
                    // support blowing up github addresses correctly
                    $parts = explode(
                        '/',
                        str_replace(
                            'git@github.com:',
                            '',
                            str_replace('.git', '', $domain)
                        )
                    );
                    if (count($parts) === 2) {
                        $domain =
                            'https://' . $parts[0] . '.github.io/' . $parts[1];
                    }
                }
            }
            // implies the domain is actually on the system locally
            else {
                $domain =
                    $GLOBALS['HAXCMS']->basePath .
                    $GLOBALS['HAXCMS']->publishedDirectory .
                    '/' .
                    $site->manifest->metadata->siteName .
                    '/';
            }
            // ensure we have the latest dynamic element loader since it may have improved from
            // when we first launched this site, HAX would have these definitions as well but
            // when in production, appstore isn't around so the user may have added custom
            // things that they care about but now magically in a published state its gone
            $site->manifest->metadata->dynamicElementLoader = $GLOBALS['HAXCMS']->config->dynamicElementLoader;
            // set last published time to now
            $site->manifest->metadata->lastPublished = time();
            $site->manifest->metadata->publishedLocation = $domain;
            $site->manifest->save(false);
            // just to be safe in case the push isn't successful
            try {
                $repo->add('.');
                $repo->commit('Clean up pre-publishing..');
                @$repo->push('origin', 'master');
            } catch (Exception $e) {
                // do nothing, we might be offline or something
                // @tood when we get into logging this would be worth logging
            }
            // now check out the publishing branch, it can't be master or our file will get mixed up
            // rather rapidly..
            if ($gitSettings->branch != 'master') {
                // try to check it out, if not then we need to create it
                try {
                    $repo->checkout($gitSettings->branch);
                    // on that branch now we need to forcibly get the master branch over top of this
                    $repo->reset('master', 'origin');
                    // now we can merge safely because we've already got the files over top
                    // as if they originated here
                    $repo->merge('master');
                } catch (Exception $e) {
                    $repo->create_branch($gitSettings->branch);
                    $repo->checkout($gitSettings->branch);
                }
            }
            // werid looking I know but if we have a CDN then we need to "rewrite" this file
            if (isset($gitSettings->cdn)) {
                $cdn = $gitSettings->cdn;
            } else {
                $cdn = 'custom';
            }
            // sanity check
            if (
                file_exists(
                    HAXCMS_ROOT . '/system/boilerplate/cdns/' . $cdn . '.html'
                )
            ) {
                // move the index.html and unlink the symlinks otherwise we'll get build failures
                if (is_link($siteDirectoryPath . '/build')) {
                    @unlink($siteDirectoryPath . '/build');
                }
                if (is_link($siteDirectoryPath . '/dist')) {
                    @unlink($siteDirectoryPath . '/dist');
                }
                if (is_link($siteDirectoryPath . '/node_modules')) {
                    @unlink($siteDirectoryPath . '/node_modules');
                }
                if (is_link($siteDirectoryPath . '/assets/babel-top.js')) {
                    @unlink($siteDirectoryPath . '/assets/babel-top.js');
                }
                if (is_link($siteDirectoryPath . '/assets/babel-bottom.js')) {
                    @unlink($siteDirectoryPath . '/assets/babel-bottom.js');
                }
                // copy these things because we have a local routine
                if ($cdn == 'custom') {
                    @copy(
                        HAXCMS_ROOT . '/babel/babel-top.js',
                        $siteDirectoryPath . '/assets/babel-top.js'
                    );
                    @copy(
                        HAXCMS_ROOT . '/babel/babel-bottom.js',
                        $siteDirectoryPath . '/assets/babel-bottom.js'
                    );
                    $GLOBALS['fileSystem']->mirror(
                        HAXCMS_ROOT . '/build',
                        $siteDirectoryPath . '/build'
                    );
                }
                // additional files to move to ensure we don't screw things up
                $templates = array(
                    'sw' => 'service-worker.js',
                    'index' => 'index.html',
                    'manifest' => 'manifest.json',
                    '404' => '404.html',
                    'msbc' => 'browserconfig.xml'
                );
                foreach ($templates as $path) {
                    rename(
                        $siteDirectoryPath . '/' . $path,
                        $siteDirectoryPath . '/_' . $path
                    );
                    // support for index as that comes from a CDN defining what to do
                    if ($path === 'index.html') {
                        $boilerPath =
                            HAXCMS_ROOT .
                            '/system/boilerplate/cdns/' .
                            $cdn .
                            '.html';
                    } else {
                        $boilerPath =
                            HAXCMS_ROOT . '/system/boilerplate/site/' . $path;
                    }
                    copy($boilerPath, $siteDirectoryPath . '/' . $path);
                }
                // process twig variables and templates for static publishing
                $templateVars = array(
                    'hexCode' => '#3f51b5',
                    // @todo support user setting a twitter account for themselves / site if desired
                    'twitter' => '',
                    'basePath' =>
                        '/' . $site->manifest->metadata->siteName . '/',
                    'title' => $site->manifest->title,
                    'short' => $site->manifest->metadata->siteName,
                    'description' => $site->manifest->description,
                    'swhash' => array(),
                    'segmentCount' => 1
                );
                // special fallback for HAXtheWeb since it cheats in order to demo the solution
                if ($cdn == 'haxtheweb.org') {
                    $templateVars['cdn'] = 'cdn.waxam.io';
                    $templateVars['cdnRegex'] =
                        "(https?:\/\/" .
                        str_replace('.', '\.', 'cdn.waxam.io') .
                        "(\/[A-Za-z0-9\-\._~:\/\?#\[\]@!$&'\(\)\*\+,;\=]*)?)";
                }
                // custom isn't a regex by design
                elseif ($cdn != 'custom') {
                    $templateVars['cdn'] = $cdn;
                    $templateVars['cdnRegex'] =
                        "(https?:\/\/" .
                        str_replace('.', '\.', $cdn) .
                        "(\/[A-Za-z0-9\-\._~:\/\?#\[\]@!$&'\(\)\*\+,;\=]*)?)";
                }
                // if we have a custom domain, try and engineer the base path
                // correctly for the manifest / service worker
                // @todo need to support domains that have subdomains in them
                if (
                    isset($site->manifest->metadata->domain) &&
                    $site->manifest->metadata->domain != ''
                ) {
                    $parts = parse_url($site->manifest->metadata->domain);
                    $templateVars['basePath'] = '/';
                    if (isset($parts['base'])) {
                        $templateVars['basePath'] = $parts['base'];
                    }
                    if ($templateVars['basePath'] == '/') {
                        $templateVars['segmentCount'] = 0;
                    }
                }
                if (isset($site->manifest->metadata->hexCode)) {
                    $templateVars['hexCode'] =
                        $site->manifest->metadata->hexCode;
                }
                $swItems = $site->manifest->items;
                // the core files you need in every SW manifest
                $coreFiles = array(
                    'index.html',
                    'manifest.json',
                    'site.json',
                    'assets/favicon.ico',
                    '404.html'
                );
                // loop through files directory so we can cache those things too
                if ($handle = opendir($siteDirectoryPath . '/files')) {
                    while (false !== ($file = readdir($handle))) {
                        if (
                            $file != "." &&
                            $file != ".." &&
                            $file != '.gitkeep' &&
                            $file != '.DS_Store'
                        ) {
                            // ensure this is a file
                            if (
                                is_file($siteDirectoryPath . '/files/' . $file)
                            ) {
                                $coreFiles[] = 'files/' . $file;
                            } else {
                                // @todo maybe step into directories?
                            }
                        }
                    }
                    closedir($handle);
                }
                foreach ($coreFiles as $itemLocation) {
                    $coreItem = new stdClass();
                    $coreItem->location = $itemLocation;
                    $swItems[] = $coreItem;
                }
                // generate a legit hash value that's the same for each file name + file size
                foreach ($swItems as $item) {
                    if (
                        $item->location === '' ||
                        $item->location === $templateVars['basePath']
                    ) {
                        $filesize = filesize(
                            $siteDirectoryPath . '/index.html'
                        );
                    } elseif (
                        file_exists($siteDirectoryPath . '/' . $item->location)
                    ) {
                        $filesize = filesize(
                            $siteDirectoryPath . '/' . $item->location
                        );
                    } else {
                        // ?? file referenced but doesn't exist
                        $filesize = 0;
                    }
                    if ($filesize !== 0) {
                        $templateVars['swhash'][] = array(
                            $item->location,
                            strtr(
                                base64_encode(
                                    hash_hmac(
                                        'md5',
                                        (string) $item->location . $filesize,
                                        (string) 'haxcmsswhash',
                                        true
                                    )
                                ),
                                array(
                                    '+' => '',
                                    '/' => '',
                                    '=' => '',
                                    '-' => ''
                                )
                            )
                        );
                    }
                }
                // put the twig written output into the file
                $loader = new \Twig\Loader\FilesystemLoader($siteDirectoryPath);
                $twig = new \Twig\Environment($loader);
                foreach ($templates as $location) {
                    @file_put_contents(
                        $siteDirectoryPath . '/' . $location,
                        $twig->render($location, $templateVars)
                    );
                }
                try {
                    $repo->add('.');
                    $repo->commit('Published using CDN: ' . $cdn);
                } catch (Exception $e) {
                    // do nothing, maybe there was nothing to commit
                }
            }
            // mirror over to the publishing directory
            // @todo need to make a way of doing this in a variable fashion
            // this way we could publish to multiple locations or intentionally to a location
            // which will be important when allowing for open, closed, or other server level configurations
            // that happen automatically as opposed to when the user hits publish
            // also for delivery of the "click to access site" link
            $GLOBALS['fileSystem']->mirror(
                $siteDirectoryPath,
                $GLOBALS['HAXCMS']->configDirectory . '/../_published/' . $site->manifest->metadata->siteName
            );
            // remove the .git version control from this, it's not needed
            $GLOBALS['fileSystem']->remove([
                $GLOBALS['HAXCMS']->configDirectory . '/../_published/' . $site->manifest->metadata->siteName . '/.git'
            ]);
            // rewrite the base path to ensure it is accurate based on a local build publish vs web
            $index = file_get_contents(
                $GLOBALS['HAXCMS']->configDirectory . '/../_published/' .
                    $site->manifest->metadata->siteName .
                    '/index.html'
            );
            // replace if it was publishing with the name in it
            $index = str_replace(
                '<base href="/' . $site->manifest->metadata->siteName . '/"',
                '<base href="' . $GLOBALS['HAXCMS']->basePath . '_published/' .
                    $site->manifest->metadata->siteName .
                    '/"',
                $index
            );
            // replace if it has a vanity domain
            $index = str_replace(
                '<base href="/"',
                '<base href="' . $GLOBALS['HAXCMS']->basePath . '_published/' .
                    $site->manifest->metadata->siteName .
                    '/"',
                $index
            );
            // rewrite the file
            @file_put_contents(
                $GLOBALS['HAXCMS']->configDirectory . '/../_published/' .
                    $site->manifest->metadata->siteName .
                    '/index.html',
                $index
            );
            // tag, attempt to push, and set things up for next time
            $repo->add_tag(
                'version-' . $site->manifest->metadata->lastPublished
            );
            @$repo->push(
                'origin',
                'version-' . $site->manifest->metadata->lastPublished,
                "--force"
            );
            if ($gitSettings->branch != 'master') {
                @$repo->push('origin', $gitSettings->branch, "--force");
                // now put it back plz... and master shouldn't notice any source changes
                $repo->checkout('master');
            }
            // restore these silly things if we need to
            if (!is_link($siteDirectoryPath . '/dist')) {
                @symlink('../../dist', $siteDirectoryPath . '/dist');
            }
            if (!is_link($siteDirectoryPath . '/node_modules')) {
                @symlink(
                    '../../node_modules',
                    $siteDirectoryPath . '/node_modules'
                );
            }
            if (is_link($siteDirectoryPath . '/assets/babel-top.js')) {
                @unlink($siteDirectoryPath . '/assets/babel-top.js');
            }
            if (is_link($siteDirectoryPath . '/assets/babel-bottom.js')) {
                @unlink($siteDirectoryPath . '/assets/babel-bottom.js');
            }
            if (is_link($siteDirectoryPath . '/build')) {
                @unlink($siteDirectoryPath . '/build');
            }
            else {
                $GLOBALS['fileSystem']->remove([$siteDirectoryPath . '/build']);
            }

            @symlink(
                '../../../babel/babel-top.js',
                $siteDirectoryPath . '/assets/babel-top.js'
            );
            @symlink(
                '../../../babel/babel-bottom.js',
                $siteDirectoryPath . '/assets/babel-bottom.js'
            );
            @symlink('../../build', $siteDirectoryPath . '/build');
            // reset the templated files to their boilerplate equivalent
            foreach ($templates as $path) {
                $GLOBALS['fileSystem']->remove([$siteDirectoryPath . '/_' . $path, $siteDirectoryPath . '/' . $path]);
                copy(HAXCMS_ROOT . '/system/boilerplate/site/' . $path, $siteDirectoryPath . '/' . $path);
            }
            return array(
                'status' => 200,
                'url' => $domain,
                'label' => 'Click to access ' . $site->manifest->title,
                'response' => 'Site published!',
                'output' => 'Site published successfully if no errors!'
            );
        }
    } else {
      return array(
            '__failed' => array(
              'status' => 500,
              'message' => 'Unable to load site',
            )
          );
    }
  }
  /**
   * clone site
   */
  public function cloneSite() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    $siteDirectoryPath = $site->directory . '/' . $site->manifest->metadata->siteName;
    $cloneName = $GLOBALS['HAXCMS']->getUniqueName($site->name);
    // ensure the path to the new folder is valid
    $GLOBALS['fileSystem']->mirror(
        HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->name,
        HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $cloneName
    );
    // we need to then load and rewrite the siteName variable or it will conflict given the name change
    $site = $GLOBALS['HAXCMS']->loadSite($cloneName);
    $site->manifest->metadata->siteName = $cloneName;
    $site->save();
    return array(
      'link' =>
        $GLOBALS['HAXCMS']->basePath .
        $GLOBALS['HAXCMS']->sitesDirectory .
        '/' .
        $cloneName,
      'name' => $cloneName
    );
  }
  /**
   * delete site
   */
  public function deleteSite() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    if ($site->name) {
      $GLOBALS['fileSystem']->remove([
        $site->directory . '/' . $site->manifest->metadata->siteName
      ]);
      return array(
        'name' => $site->name,
        'detail' => 'Site deleted',
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Site does not exist!',
        )
      );
    }
  }
  public function downloadSite() {
    // load site
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    // helpful boilerplate https://stackoverflow.com/questions/29873248/how-to-zip-a-whole-directory-and-download-using-php
    $dir = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->name;
    // form a basic name
    $zip_file =
      HAXCMS_ROOT .
      '/' .
      $GLOBALS['HAXCMS']->publishedDirectory .
      '/' .
      $site->name .
      '.zip';
    // Get real path for our folder
    $rootPath = realpath($dir);
    // Initialize archive object
    $zip = new ZipArchive();
    $zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    // Create recursive directory iterator
    $directory = new RecursiveDirectoryIterator($rootPath);
    $filtered = new DirFilter($directory, array('node_modules'));
    $files = new RecursiveIteratorIterator($filtered);
    foreach ($files as $name => $file) {
      // Skip directories (they would be added automatically)
      if (!$file->isDir()) {
        // Get real and relative path for current file
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($rootPath) + 1);
        // Add current file to archive
        if ($filePath != '' && $relativePath != '') {
          $zip->addFile($filePath, $relativePath);
        }
      }
    }
    // Zip archive will be created only after closing object
    $zip->close();
    return array(
      'link' =>
        $GLOBALS['HAXCMS']->basePath .
        $GLOBALS['HAXCMS']->publishedDirectory .
        '/' .
        basename($zip_file),
      'name' => basename($zip_file)
    );
  }
  /**
   * archive site
   */
  public function archiveSite() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['siteName']);
    if ($site->name) {
      rename(
        HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->siteName,
        HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->archivedDirectory . '/' . $site->manifest->metadata->siteName);
      return array(
        'name' => $site->name,
        'detail' => 'Site archived',
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Site does not exist',
        )
      );
    }
  }
}