<?php

/**
 * @file
 * Contains \Drupal\clutch\ClutchBuilder.
 */

namespace Drupal\clutch;

const QE_CLASS = 'quickedit-field';
const QE_FIELD_ID = 'data-quickedit-field-id';
const QE_ENTITY_ID = 'data-quickedit-entity-id';

require_once(dirname(__DIR__).'/libraries/wa72/htmlpagedom/src/Helpers.php');
require_once(dirname(__DIR__).'/libraries/wa72/htmlpagedom/src/HtmlPageCrawler.php');
require_once(dirname(__DIR__).'/libraries/wa72/htmlpagedom/src/HtmlPage.php');

use Drupal\component\Entity\Component;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\Entity\File;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\CssSelector\CssSelector;
use Wa72\HtmlPageDom\HtmlPageCrawler;
use Drupal\clutch\ParagraphBuilder;
use Drupal\clutch\TabBuilder;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\clutch\MenuBuilder;
use Drupal\image\Entity\ImageStyle;
use Drupal\clutch\FormBuilder;

/**
 * Class ClutchBuilder.
 *
 * @package Drupal\clutch\Controller
 */
abstract class ClutchBuilder {

  protected $twig_service;
  public function __construct() {
    $this->twig_service = \Drupal::service('twig');
  }

  /**
   * Load template using twig engine.
   * @param string $template
   *
   * @return string
   *   Return html string from template
   */
  abstract public function getHTMLTemplate($template);

  /**
   * Find and replace static value with dynamic value from created content
   *
   * @param $template, $entity, $view_mode
   *   html string template from component
   *   component entity
   *   view mode of the entity
   *
   * @return
   *   render html for entity
   */
  public function findAndReplace($template, $entity, $view_mode = NULL) {
    $html = $this->getHTMLTemplate($template, $view_mode);
    $crawler = new HtmlPageCrawler($html);

    if($crawler->filterXPath('//*[@data-menu]')->count()) {
      $crawler = $this->findAndReplaceValueForMenuLinks($crawler);
    }
    
    // get entity view display for entity.
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    if(($entity_type_id == 'component') || ($entity_type_id == 'node' && $view_mode == 'full')) {
      $view_mode = 'default';
    }

    $entity_display = \Drupal::entityManager()->getStorage('entity_view_display')->load($entity_type_id . '.' . $bundle . '.' . $view_mode);  

    $html = $this->findAndReplaceValueForFields($crawler, $entity, $entity_display, $view_mode);
    return $html;
  }

  /**
   * Replacing value for fields
   * 
   * @param $crawler, $entity, $entity_display, $view_mode
   *   crawler object
   *   entity
   *   entity view display
   *   view mode (use to decide adding quickedit)
   *
   * @return
   *   render crawler object/render markup
   */
  public function findAndReplaceValueForFields($crawler, $entity, $entity_display, $view_mode) {
    $fields = $this->collectFields($entity);
    $crawler = $this->routeImagePath($crawler);
    $bundle = $entity->bundle();

    foreach($fields as $field_name => $field_value) {
      if(!strpos($field_name, 'field') !== false) {
        $field_display = $entity_display->getComponent($bundle . '_' . $field_name);
      }else {
        $field_display = $entity_display->getComponent($field_name);
      }
      if($crawler->filter('[data-field="'.$field_name.'"]')->count()) {
        $crawler = $this->findAndReplaceValueForFieldBasedOnType($crawler, $field_name, $field_value, $field_display, $view_mode);
      }
    }

    // Find and replace title last
    if($entity->getEntityTypeId() == 'node') {
      $crawler->filter('[data-title="title"]')->text($fields['title']['content']['value']);
      if($view_mode == 'default') {
        $crawler->filter('[data-title="title"]')->addClass(QE_CLASS)->setAttribute(QE_FIELD_ID, $fields['title']['quickedit']);
        $crawler->filter('[data-title="title"]')->removeAttr('data-title');
      }
      if($crawler->filter('[data-date="date"]')->count()) {
        $date = $entity->get('created')->value;
        $date = date('M d Y', $date);
        $crawler->filter('[data-date="date"]')->text($date);
        $crawler->filter('[data-date="date"]')->removeAttr('data-date');
      }
    }
        
    return $crawler;
  }

  /**
   * Replacing value for field based on field type and field display
   * 
   * @param $crawler, $field_name, $field, $field_display, $view_mode
   *   crawler object
   *   field name
   *   field array
   *   field display array
   *   view mode
   *
   * @return
   *   render crawler object/render markup after replacing value
   */
  public function findAndReplaceValueForFieldBasedOnType($crawler, $field_name, $field, $field_display, $view_mode) {
    $field_crawler = $crawler->filter('[data-field="'.$field_name.'"]');
    switch($field['type']) {
      case 'link':
        $field['content']['uri'] = str_replace('internal:/', '', $field['content']['uri']);
        $field_crawler->setAttribute('href', '/'.$field['content']['uri'])->text($field['content']['title']);
        break;

      case 'image':
        $field_crawler = $this->findAndReplaceImage($field_crawler, $field, $field_display);
        $crawler = $this->handleLightbox($crawler, $field_name, $field);
        break;

      case 'iframe':
        $field_crawler->setAttribute('src', $field['content']['url']);
        $field_crawler->setAttribute('width', $field['content']['width']);
        $field_crawler->setAttribute('height', $field['content']['height']);
        break;

      case 'file':
        if($field_crawler->getAttribute('href')) {
          $field_crawler->setAttribute('href', $field['content']['url']);
        }else {
          $field_crawler->setAttribute('src', $field['content']['url']);
        }
        break;

      case 'entity_reference':
        $field_crawler = $this->findAndReplaceEntityReference($crawler, $field_name, $field);
        break;

      case 'entity_reference_revisions':
        $field_crawler = $this->findAndReplaceValueForParagraph($field_name, $field_crawler, $field);
        break;

      default:
        $field_crawler = $this->findAndReplaceText($field_crawler, $field, $field_display);
        break;
    }

    // adding quickedit for field 
    if($view_mode == 'default') {
      $crawler = $this->findAndReplaceAddingQuickEdit($crawler, $field_name, $field);
    }
    // remove necessary markup
    $crawler = $this->findAndReplaceCleanUpMarkup($crawler, $field_name);
    return $crawler;
  }

  public function handleLightbox($crawler, $field_name, $field) {
    if($crawler->filter('.w-lightbox')->count()) {
      $crawler->filter('script')->remove();
      $crawler->filter('.w-lightbox')->append('
        <script type="application/json" class="w-json">
          { "items": [{
              "type": "image",
              "url": "'. $field['content']['url'] .'"
            }] 
          }
        </script>');
    }
    return $crawler;
  }
  /**
   * Replacing value for image field
   * 
   * @param $crawler, $field_name, $field, $field_display
   *   crawler object
   *   field name
   *   field array
   *   field display 
   *
   * @return
   *   render crawler object/render markup after replacing value with correct format
   */
  public function findAndReplaceImage($crawler, $field, $field_display) {
    $image_style = $field_display['settings']['image_style'];
    $image = $this->generateImageStyle($field, $image_style);
    switch($field_display['type']) {
      case 'image':
        $crawler->setAttribute('src', $image)->setAttribute('alt', $field['content']['alt']);
        break;
      case 'bg_image_formatter':
        $crawler = $this->handleBackgroundImage($crawler, $field_display, $image);
        break;
    }
    return $crawler;
  }

  /**
   * Generate image style
   * 
   * @param $field, $image_style
   *   field name
   *   image style
   *
   * @return
   *   full url of image style
   */
  public function generateImageStyle($field, $image_style) {
    if(empty($field['content'])) {
      return;
    }
    $file = File::load($field['content']['target_id']);
    $uri = $file->get('uri')->value;
    if($image_style == '') {
      return file_create_url($uri);  
    }else {
      $image_style_object = ImageStyle::load($image_style);
      $image_style_uri = $image_style_object->buildUrl($uri);
      return file_create_url($image_style_uri);
    }
  }

  /**
   * Replacing value for text/text-long/text-plain/text-and-summary
   * 
   * @param $crawler, $field, $field_display
   *   crawler object
   *   field array
   *   field display
   *
   * @return
   *   render crawler object/render markup after replacing value with correct format
   */
  public function findAndReplaceText($crawler, $field, $field_display) {
    switch($field_display['type']) {
      case 'text_default':
      case 'string':
        $content = $field['content']['value'];
        break;
      case 'text_trimmed':
        $content = text_summary($field['content']['value'], $format = NULL, $field_display['settings']['trim_length']);
        break;
    }
    return $crawler->setInnerHtml($content);

  }

  /**
   * Replacing value for field entity reference
   * 
   * @param $crawler, $field_name, $field
   *   crawler object
   *   field name
   *   field array
   *
   * @return
   *   render crawler object/render markup after replacing value
   */
  public function findAndReplaceEntityReference($crawler, $field_name, $field) {
     // dpm($field['handler']);
    switch($field['handler']) {

      case 'default:view':
        $view_render_array = views_embed_view($field['target_id']);
        $crawler->filter('[data-field="'.$field_name.'"]')->append(drupal_render($view_render_array)->__toString());
        break;

      case 'default:block':
        $block = \Drupal\block\Entity\Block::load($field['target_id']);
        $block_content = \Drupal::entityManager()
          ->getViewBuilder('block')
          ->view($block);
        $crawler->filter('[data-field="'.$field_name.'"]')->append(drupal_render($block_content)->__toString());
        break;
    }
  }

  /**
   * Append background image styling to crawler
   * 
   * @param $crawler, $bg_display_component, $image
   *   crawler object
   *   display array of background image component
   *   image url
   *
   * @return
   *   render crawler object/render markup after append background image value
   */
  public function handleBackgroundImage($crawler, $bg_display_component, $image) {
    if(!empty($image)) {
      $media_query = $bg_display_component['settings']['css_settings']['bg_image_media_query'];
      $css_settings = $bg_display_component['settings']['css_settings'];
      $css = bg_image_add_background_image($image, $css_settings);
      $crawler->append('<style type="text/css">@media '. $media_query . '{' . $css . '}</style>');
      return $crawler;
    }
    // better solution is import background image through header
      // $elements = array();

      // $elements = [[
      //   '#tag' => 'style',
      //   '#attributes' => [
      //     'media' => $media_query
      //   ],
      //   '#value' => $css
      // ], 'bg_image_formatter_css_' . $entity->id()];
      // drupal_render($elements);
  }

  /**
   * Add quickedit only for admin user
   * 
   * @param $crawler, $field_name, $field
   *   crawler object
   *   field name
   *   field array
   *
   * @return
   *   render crawler object/render markup after adding quickedit
   */
  public function findAndReplaceAddingQuickEdit($crawler, $field_name, $field) {
    if(in_array('administrator', \Drupal::currentUser()->getRoles())) {
      $crawler->filter('[data-field="'.$field_name.'"]')->addClass(QE_CLASS)->setAttribute(QE_FIELD_ID, $field['quickedit']);
    }
    return $crawler;
  }

  /**
   * Clean up unnecessary markup (data-attributes)
   * 
   * @param $crawler, $field_name
   *   crawler object
   *   field name
   *
   * @return
   *   render crawler object/render markup after cleanup markup
   */
  public function findAndReplaceCleanUpMarkup($crawler, $field_name) {
    $crawler->filter('[data-field="'.$field_name.'"]')->removeAttr('data-type')->removeAttr('data-form-type')->removeAttr('data-format-type')->removeAttr('data-field');
    return $crawler;
  } 

  /**
   * Change image path before render
   *
   * @param  $crawler
   *   crawler instance of class Crawler - Symfony
   *
   * @return
   *   crawler instance with update html
   */
  public function routeImagePath(Crawler $crawler) {
    $crawler->filter('img')->each(function (Crawler $node, $i) {
      if($node->filterXpath('//*[@data-field]')->count() == 0) {
        $temp_url = $node->getAttribute('src');
        // $public_folder = \Drupal::service('stream_wrapper_manager')->getViaUri('public://')->baseUrl();
        $theme_array = $this->getCustomTheme();
        $theme_name = array_keys($theme_array)[0];
        $uri = drupal_get_path('theme', $theme_name);
        $full_url = '/' . $uri . '/' . $temp_url;
        $node->setAttribute('src', $full_url);
      }
    });
    return $crawler;
  }

  /**
   * Find and replace values for individual paragraph
   *
   * @param  $crawler, $field, $field_name
   *   crawler instance of class Crawler - Symfony
   *   array of field value
   *   field name
   *
   * @return
   *   crawler instance with update html
   */
  public function findAndReplaceValueForParagraph($field_name, $crawler, $field) {
    $paragraph_template = $crawler->filter('.collection')->eq(0)->saveHTML();
    $crawler->filter('[data-field="'.$field_name.'"]')->setInnerHtml('');
    $index = 0;
    foreach($field['value'] as $fields_in_paragraph) {
      $paragraph_children = new HtmlPageCrawler($paragraph_template);
      $paragraph_children_html = $this->setupWrapperForParagraph($paragraph_children, $fields_in_paragraph);
      $paragraph_children_html->addClass($index);
      $crawler->filter('[data-field="'.$field_name.'"]')->append($paragraph_children_html);
      if($crawler->filterXpath('//*[@data-w-tab]')->count()) {
        $crawler->filter('.w-tab-link')->eq($index)->setInnerHtml($fields_in_paragraph['value']['tab_title']['content']['value']);
        $index++;
      }
    }
    
    $crawler->filter('.w-tab-pane')->each(function (Crawler $node, $i) {
      $node->setAttribute('data-w-tab', "Tab " . ($i+1));
      $node->addClass("tab-" . ($i+1));
      return $node;
    });

    if($crawler->filterXpath('//*[@data-w-tab]')->count()) {
      $crawler->filter('.w-tab-pane')->removeClass('w--tab-active');
      $crawler->filter('.w-tab-pane')->eq(0)->addClass('w--tab-active');
    }

    return $crawler;
  }

  public function findAndReplaceValueForMenuLinks($crawler) {
    $menu_item_template = $crawler->filter('nav.nav-menu a')->eq(0);
    $menu_name = $crawler->filterXpath('//*[@data-menu]')->extract('data-menu')[0];
    $crawler->filter('nav.nav-menu')->setInnerHtml('');
    $menu_items = \Drupal::entityQuery('menu_link_content')->condition('menu_name',$menu_name)->sort('weight')->execute();
    $menu_item_objects = MenuLinkContent::loadMultiple($menu_items);
    foreach($menu_item_objects as $menu_item_object){
      $menu_item_array = $menu_item_object->toArray();
      $menu_item_uri = str_replace('internal:', '', $menu_item_array['link'][0]['uri']);
      $menu_item_name = $menu_item_array['title'][0]['value'];
      $new_menu_link = $menu_item_template->setAttribute('href', $menu_item_uri)->text($menu_item_name);
      $crawler->filter('nav.nav-menu')->append($new_menu_link->saveHTML());
    }
    return $crawler;
  }

  /**
   * wrap correct wrapper around individual paragraph
   * to make it quickeditable
   *
   * @param $crawler, $fields
   *   crawler of the paragraph
   *   array of fields to replace in paragraph
   *
   * @return
   *   crawler/html with correct wrapper for individual paragraph
   */
  public function setupWrapperForParagraph($crawler, $fields) {
    foreach($fields['value'] as $field_name => $field) {
      if($crawler->filter('[data-paragraph-field="'.$field_name.'"]')->count()) {
        if($crawler->filter('[data-paragraph-field="'.$field_name.'"]')->getAttribute('data-type') == 'image') {
          // temporary remove quickedit for image
          $crawler->filter('[data-paragraph-field="'.$field_name.'"]')->setAttribute('src', $field['content']['url'])->setAttribute('alt', $field['content']['alt']);
          // $crawler->filter('[data-paragraph-field="'.$field_name.'"]')->removeAttr('data-type')->removeAttr('data-form-type')->removeAttr('data-format-type')->removeAttr('data-paragraph-field')->setInnerHtml($field['content']['value']);
        }elseif ($crawler->filter('[data-paragraph-field="'.$field_name.'"]')->getAttribute('data-type') == 'file'){
          if($crawler->filter('[data-paragraph-field="'.$field_name.'"]')->getAttribute('href')) {
            $crawler->filter('[data-paragraph-field="'.$field_name.'"]')->setAttribute('href', $field['content']['url']);  
          }else {
            $crawler->filter('[data-paragraph-field="'.$field_name.'"]')->setAttribute('src', $field['content']['url']);
          }
        }else {
          $crawler->filter('[data-paragraph-field="'.$field_name.'"]')->addClass(QE_CLASS)->setAttribute(QE_FIELD_ID, $field['quickedit'])->removeAttr('data-type')->removeAttr('data-form-type')->removeAttr('data-format-type')->removeAttr('data-paragraph-field')->setInnerHtml($field['content']['value']);
        }
      }
    }
    $crawler->filter('.collection')->setAttribute('data-quickedit-entity-id', $fields['quickedit']);
    return $crawler;
  }

  /**
   * Collect Fields
   *
   * @param $entity
   *   entity object
   *
   * @return
   *   array of fields belong to this object
   */
  public function collectFields($entity) {
    $bundle = $entity->bundle();
    $fields = array();
    $fields_definition = $entity->getFieldDefinitions();
    foreach($fields_definition as $field_definition) {
     if(!empty($field_definition->getTargetBundle())) {
       if($field_definition->getType() == 'entity_reference_revisions') {
        $paragraph_fields = array();
        $field_name = $field_definition->getName();
        $entity_paragraph_field = str_replace($bundle.'_', '', $field_name);
        $field_values = $entity->get($field_name)->getValue();
        $field_language = $field_definition->language()->getId();
        foreach($field_values as $field_value) {

          $paragraph = entity_load('paragraph', $field_value['target_id']);
          $paragraph_builder = new ParagraphBuilder();
          $paragraph_fields['paragraph_'.$paragraph->id()]['value']= $paragraph_builder->collectFields($paragraph, $field_definition);
          $paragraph_fields['paragraph_'.$paragraph->id()]['quickedit'] = 'paragraph/' . $paragraph->id();
        }
        $fields[$entity_paragraph_field]['value'] = $paragraph_fields;
        $fields[$entity_paragraph_field]['quickedit'] = $entity->getEntityTypeId() . '/' . $entity->id() . '/' . $field_name . '/' . $field_language . '/full';
        $fields[$entity_paragraph_field]['type'] = 'entity_reference_revisions';
       }else {
         $non_paragraph_field = $this->collectFieldValues($entity, $field_definition);
         $key = key($non_paragraph_field);
         $fields[$key] = $non_paragraph_field[$key];
       }
     }
    }
    if($entity->getEntityTypeId() == 'node') {
      $fields['title']['content']['value'] = $entity->getTitle();
      $fields['title']['quickedit'] = 'node/' . $entity->id() . '/title/en/full';
    }
    return $fields;
  }

  /**
   * Collect Field Values
   *
   * @param $entity, $field_definition
   *   entity object
   *   field definition object
   *
   * @return
   *   array of value for this field
   */
  abstract public function collectFieldValues($entity, $field_definition);


  /**
   * Create entities from template
   *
   * @param $bundles
   *   array of bundles
   *
   * @return
   *   TODO
   */
  public function createEntitiesFromTemplate($bundles) {
    //make array if only creating one component (if string passed in)
    if(!is_array($bundles)) {
      $val = $bundles;
      $bundles = array($bundles);
    }
    foreach($bundles as $bundle) {
      $this->createEntityFromTemplate(str_replace('_', '-', $bundle));
    }
  }

  /**
   * Create entity from template
   *
   * @param $template
   *   html string template from theme
   *
   * @return
   *   return entity object
   */
  public function createEntityFromTemplate($template) {
    $entity_info = $this->prepareEntityInfoFromTemplate($template);
    $crawler = $entity_info['crawler'];
    $bundle_info = $entity_info['entity'];
    if($crawler->filterXPath('//*[@data-menu]')->count()) {
      $menu_builder = new MenuBuilder;
      $menu_builder->createMenu($crawler);
    }
    // $this->createBundle($bundle_info);
  }

  /**
   * Create bundle
   *
   * @param $bundle
   *   array of bundle info
   *
   * @return
   *   return bundle object
   */
  abstract public function createBundle($bundle_info);


  public function createFields($bundle) {
    foreach($bundle['fields'] as $field) {
      $this->createField($bundle['id'], $field);
    }
  }

  /**
   * create field and associate to bundle
   *
   * @param $bundle, $field
   *   bundle machine name
   *   array of field info
   *
   * @return
   *   TODO
   */
  abstract public function createField($bundle, $field);

  /**
   * Prepare entity to create bundle and content
   *
   * @param $template
   *   html string template from theme
   *
   * @return
   *   An array of entity info.
   */
  public function prepareEntityInfoFromTemplate($template) {
    $html = $this->getHTMLTemplate($template);
    $crawler = new HtmlPageCrawler($html);
    $entity_info = array();
    $bundle = $this->getBundle($crawler);
    $entity_info['id'] = $bundle;
    $fields = $this->getFieldsInfoFromTemplate($crawler, $bundle);
    //fields array inside $fields['fields'] (was just $fields) 
    $entity_info['fields'] = $fields;
    return [ 'crawler' => $crawler, 'entity' => $entity_info];
  }

  /**
   * Look up bundle information from template
   *
   * @param $crawler
   *   crawler instance of class Crawler - Symfony
   *
   * @return
   *   An array of bundle info.
   */
  abstract public function getBundle(Crawler $crawler);

  /**
   * Look up fields information from template
   *
   * @param $crawler, $bundle
   *   crawler instance of class Crawler - Symfony
   *   bundle value
   *
   * @return
   *   An array of fields info.
   */
  public function getFieldsInfoFromTemplate(Crawler $crawler, $bundle) {
    $fields = $crawler->filterXPath('//*[@data-field][not(ancestor::form)]')->each(function (Crawler $node, $i) use ($bundle) {
      $field_type = $node->extract(array('data-type'))[0];
      $field_name = $bundle . '_' . $node->extract(array('data-field'))[0];
      $field_form_display = $node->extract(array('data-form-type'))[0];
      $field_formatter = $node->extract(array('data-format-type'))[0];
      $default_value = NULL;

      switch($field_type) {
        case 'link':
          $default_value['uri'] = $node->extract(array('href'))[0];
          $default_value['title'] = $node->extract(array('_text'))[0];
          break;

        case 'image':
          $default_value = $node->extract(array('src'))[0];
          break;

        case 'entity_reference_revisions':
          return $this->getFieldsInfoFromTemplateForParagraph($node, $field_name);
          break;
        
        case 'iframe':
          $default_value['url'] = $node->extract(array('src'))[0];
          $default_value['width'] = $node->extract(array('width'))[0];
          $default_value['height'] = $node->extract(array('height'))[0];
          break;

        case 'entity_reference':
          if($node->filterXPath('form')->count()) {
            //returns form id
             $default_value = $this->createFormAndReturnId($node, $field_name, $bundle);
          }
          break;

        default:
          $default_value = $node->getInnerHtml();
          break;
      }      

      return array(
        'id' => $bundle,
        'field_name' => $field_name,
        'field_type' => $field_type,
        'field_form_display' => $field_form_display,
        'field_formatter' => $field_formatter,
        'value' => $default_value,
      );
    });   
    // dpm($fields);         
    return $fields;
  }

  public function createFormAndReturnId($crawler, $field_name, $bundle) {
    $form_builder = new FormBuilder;
    $fields = $crawler->filterXPath('//*[@data-field][ancestor::form]')->each(function (Crawler $node, $i) use ($bundle) {
      return array(
        'field_name' => $bundle . '_' . $node->extract(array('data-field'))[0],
        'field_type' => $node->extract(array('data-type'))[0],
        'field_form_display' => $node->extract(array('data-form-type'))[0],
        'field_formatter' => $node->extract(array('data-format-type'))[0],
        'value' => $node->getInnerHtml(),
      );
    });
    $bundle_info = array (
      'id' => $bundle,
      'fields' => $fields,
    );

    $form_builder->createBundle($bundle_info);
    return $bundle;//form id
  }

  public function getFieldsInfoFromTemplateForParagraph($crawler, $field_name) {
    // this crawler will crawl the paragraph html in the template
    $paragraph_crawler = new HtmlPageCrawler($crawler->getInnerHtml());
    $paragraph_bundle = $crawler->extract(array('data-field'))[0];

    //check if this is webflow tab component
    if($crawler->filterXPath('//*[@data-w-tab]')->count()) {
      $paragraph_builder = new TabBuilder();
    }else {
      $paragraph_builder = new ParagraphBuilder();
    }
    $paragraph_fields = $paragraph_builder->getFieldsInfoFromTemplate($paragraph_crawler, $paragraph_bundle);
    $paragraph = array(
      'id' => $paragraph_bundle,
      'fields' => $paragraph_fields,
    );
    $default_value = $paragraph_builder->createBundle($paragraph);
    return array(
      'field_name' => $field_name,
      'field_type' => 'entity_reference_revisions',
      'field_form_display' => 'entity_reference_paragraphs',
      'field_formatter' => 'entity_reference_revisions_entity_view',
      'value' => $default_value,
    );
  }
  /**
   * Find bundles that need to be updated
   *
   * @param $bundles
   *   array of bundles
   *
   * @return
   *   An array bundles that need to be updated
   */
  public function getNeedUpdateComponents($bundles) {
    $need_to_update_bundles = array();
    foreach($bundles as $bundle => $label) {
      if($this->verifyIfBundleNeedToUpdate($bundle)) {
        $need_to_update_bundles[$bundle] = $label;
      }
    }
    return $need_to_update_bundles;
  }

  /**
   * Get front end theme directory
   * @return
   *  an array of theme namd and theme path
   */
  public function getCustomTheme() {
    $themes = system_list('theme');
    foreach($themes as $theme) {
      if($theme->origin !== 'core') {
        return [$theme->getName() => $theme->getPath()];
      }
    }
  }
  
  /**
   * Create default content for entity
   * 
   * @param $content, $type
   *  array of content information
   *  entity type
   *
   * @return
   *  paragraph object id
   */
  public function createDefaultContentForEntity($content, $type) {
    $entity = NULL;
    $theme_array = $this->getCustomTheme();
    $theme_name = array_keys($theme_array)[0];
    $file_directory = 'default';
    switch($type) {
      case 'component':
        $entity = Component::create([
          'type' => $content['id'],
          'name' => ucwords(str_replace('_', ' ', $content['id'])),
        ]);
        $entity->save();
        $file_directory = 'components/' . str_replace('_', ' ', $content['id']);
        break;

      case 'paragraph':
        $entity = Paragraph::create([
          'type' => $content['id'],
          'title' => ucwords(str_replace('_', ' ', $content['id'])),
        ]);
        $entity->save();
        $file_directory = 'paragraphs/' . str_replace('_', ' ', $content['id']);
        break;
    }

    foreach($content['fields'] as $field) {
      if($field['field_type'] == 'image') {
        $settings['file_directory'] = $file_directory . '/[date:custom:Y]-[date:custom:m]';
        $uri = drupal_get_path('theme', $theme_name) .'/'. $field['value'];
        if (file_exists($uri) && !is_dir($uri)) {
          $image = File::create();
          $image->setFileUri($uri);
          $image->setOwnerId(\Drupal::currentUser()->id());
          $image->setMimeType('image/' . pathinfo($field['value'], PATHINFO_EXTENSION));
          $image->setFileName(drupal_basename($field['value']));
          $destination_dir = 'public://' . $file_directory;
          file_prepare_directory($destination_dir, FILE_CREATE_DIRECTORY);
          $destination = $destination_dir . '/' . basename($field['value']);
          $file = file_copy($image, $destination);
          $values = array(
            'target_id' => $file->id(),
          );
          $entity->set($field['field_name'], $values);
        }
      }elseif($field['field_type'] == 'file') {
        // handle later. file in webflow is href. need an actual file to recognize and upload
      }else {
        $entity->set($field['field_name'], $field['value']);
      }
    }
    $entity->save();
    \Drupal::logger('clutch:workflow')->notice('Create content for type @type - bundle @bundle',
      array(
        '@type' => $type,
        '@bundle' => $content['id'],
      ));
    return $entity;
  }
}