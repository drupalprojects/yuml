<?php

/**
 * @file
 * Contains \Drupal\yuml\Plugin\Filter\FilterYUmlFormat.
 */

namespace Drupal\yuml\Plugin\Filter;

use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter for rendering yUML Format.
 *
 * @Filter(
 *   id = "filter_yuml",
 *   module = "yuml",
 *   title = @Translation("yUML Format"),
 *   description = @Translation("Use the http://yuml.me service to generate UML diagrams."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   settings = {
 *     "url" = "http://yuml.me",
 *   },
 *   weight = -10
 * )
 */
class FilterYUmlFormat extends FilterBase {

  static $START_TOKEN = "[yuml";
  static $END_TOKEN = "]";
  var $text = NULL;
  var $start = -1;
  var $end = -1;
  var $meta = array();
  var $lines = array();

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, array &$form_state) {
    $form['url'] = array(
      '#type' => 'textfield',
      '#title' => t('What is the service URL for yUML?'),
      '#default_value' => $this->settings['url'],
      '#maxlength' => 1024,
      '#description' => t('You may change the yUML service endpoint.'),
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode, $cache, $cache_id) {
    $this->text = $text;

    $this->start = strpos($this->text, "\n" . FilterYUmlFormat::$START_TOKEN, 0);
    while ($this->start !== FALSE) {
      // skip new line
      $this->start++;
      if ($this->parse()) {
        $this->replace();
        $this->meta = array();
      }
      else {
        break;
      }
      $this->start = strpos($this->text, "\n" . FilterYUmlFormat::$START_TOKEN, $this->start);
    }
    return $this->text;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return self::help($long);
  }

  static function help($long = FALSE) {
    if ($long) {
      return 'With yUML Format you can create inline UML Diagrams.<br/>'
          . self::options_help() . '<br/>'
          . 'For more info <a href="http://yuml.me">yUML format</a>.'
      ;
    }
    else {
      return "Use yUML to generate inline UML Diagrams.";
    }
  }

  function parse() {
    $this->end = strpos($this->text, "\n" . FilterYUmlFormat::$END_TOKEN, $this->start);
    if ($this->start < $this->end) {
      $this->end++;
      $lines = explode("\n", substr($this->text, $this->start, $this->end - $this->start));
      // consume first line [yuml ...
      $meta = array_shift($lines);
      $this->parseMeta($meta);

      if ($this->meta === FALSE) {
        // No need to parse as we don't know how to render
        // Make sure parsing continues.
        return TRUE;
      }

      $this->lines = array();

      $mode = 'lines';
      while ($line = array_shift($lines)) {
        $line = trim($line);
        if ($line == '#') {
          $mode = 'links';
        }
        elseif (empty($line)) {
          // Skip empty lines
        }
        else if ($mode == 'lines') {
          $this->lines[] = $line;
        }
        else if ($mode == 'links') {

        }
      }
      return TRUE;
    }
    return FALSE;
  }

  function replace() {
    if ($this->start != $this->end) {
      if ($this->meta === FALSE) {
        // Skip block found.
        $this->start = $this->end;
        return;
      }
      $config = $this->settings;
      if (!empty($this->meta)) {
        $config = array_merge($config, $this->meta);
      }
      $meta = $this->meta;
      $settings = array(
        'url' => $config['url'],
        'diagram' => $meta['diagram'],
        'style' => $meta['style'] . ';dir:' . $meta['dir'] . ';scale:' . $meta['scale'],
        'content' => join(',', $this->lines),
      );
      $url = sprintf("%s/diagram/%s;/%s/%s", $settings['url'], $settings['style'], $settings['diagram'], $settings['content']);
      $result = '<img src="' . $url . '" />';
      if ($meta['debug']) {
        $result .= '<xmp>' . $result . '</xmp>';
      }
      $this->text = substr($this->text, 0, $this->start) . $result . substr($this->text, $this->end + 1);
    }
  }

  static function options_help() {
    $text = "<dl>";

    foreach (static::options() as $key => $option) {
      $text .= '<dt>' . $key . '</dt>';
      $text .= '<dd>';
      $lines = array();
      if (isset($option['required']) && $option['required']) {
        $lines[] = t("Required");
      }
      $lines[] = t("Valid values are: %values", array('%values' => join(', ', $option['values'])));
      if (isset($option['default']) && $option['default']) {
        $lines[] = t('Default value: %default.', array('%default' => $option['default']));
      }
      $text .= join("<br/>", $lines);
      $text .= '<dd>';
    }

    $text .= "</dl>";
    $text .= "Place this between &lt;pre&gt; tags <pre>\n[yuml diagram:usecase style:scruffy\n[Text editor]-(Writes article)\n(Writes article)>(Drupal)\n(Drupal)>(Uses yUML)\n]</pre>";
    return $text;
  }

  static function options() {
    return array(
      'debug' => array(
        'values' => array(0, 1),
        'default' => 0,
      ),
      'diagram' => array(
        'required' => TRUE,
        'values' => array(
          'class', 'activity', 'usecase'
        ),
      ),
      'scale' => array(
        'values' => array(180, 120, 100, 80, 60),
        'default' => 100,
      ),
      'dir' => array(
        'values' => array('LR', 'TD', 'RL'),
        'format' => 'dir:%s',
        'default' => 'LR',
      ),
      'style' => array(
        'values' => array(
          'nofunky',
          'plain',
          'scruffy',
        ),
        'default' => 'plain',
        'format' => 'style:%s',
      ),
    );
  }

  /**
   * Process meta line to set engine etc.
   *
   * @param string $meta
   *   Contains '[yuml ...'
   */
  function parseMeta($meta) {
    $options = static::options();

    $result = array();
    // The start is done by a [ which must be escaped for the regex: \\[
    $meta = preg_replace("/\\" . FilterYUmlFormat::$START_TOKEN . "/", '', $meta, 1);
    $meta = trim($meta);
    $metas = preg_split("/ /", $meta);
    foreach ($metas as $key_value) {
      if (strpos($key_value, ':') !== FALSE) {
        list($key, $value) = preg_split('/:/', $key_value);
        if (isset($options[$key])) {
          $values = $options[$key]['values'];
          if (in_array($value, $values)) {
            $result[$key] = $value;
          }
          else {
            drupal_set_message("Invalid value for $key");
          }
        }
      }
    }
    $this->meta = $result;
    foreach ($options as $key => $settings) {
      if (isset($settings['required']) && $settings['required']) {
        if (!isset($result[$key])) {
          if (!isset($settings['default'])) {
            drupal_set_message("yUML: Missing option: " . $key);
            $this->meta = FALSE;
          }
          else {
            $result[$key] = $settings['default'];
          }
        }
      }
      if (!isset($result[$key]) && isset($settings['default'])) {
        $result[$key] = $settings['default'];
      }
    }
    if ($this->meta !== FALSE) {
      $this->meta = $result;
    }
  }

}
