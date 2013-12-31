<?php

class google_ics_fix {
  
  public /** @type {Array} */ $cal;
  /* Which keyword has been added to cal at last? */
  private /** @type {string} */ $last_keyword;
  private $raw_extra;

  /* 
   * Input: Google formatted ICS file
   * Output: Recurring Event Fixed ICS file (string_location_to_fixed_file)
   */
  public function fixFile($ics_file) {

    $ics_string = $this->readFromFile($ics_file);

    //apply duplicate repeating fix
    $this->fixRepeatingUids();

    //write new fixed file
    $this->writeFixedCal($ics_file.'_gcal_fixed');

    return $ics_file.'_gcal_fixed';

  }

  private function fixRepeatingUids()
  {
    $remove_list = array();

    if(!isset($this->cal['VEVENT']) || count($this->cal['VEVENT']) == 0) {
      return;
    }

    foreach($this->cal['VEVENT'] as $i => $event) {

      if( preg_match("/_R[0-9]{8}T[0-9]{6}/u", $event['UID'], $matches) ) {
        // looks like we found a possible hit for this hack
        // check to see if another event has the naturalized uid. If so
        // we want to remove it
        $naturalized_uid = str_replace($matches[0],'',$event['UID']);
        $remove_list[] = $naturalized_uid;
      }
    }

    $new_vevent = array();
    foreach( $this->cal['VEVENT'] as $i => $event) {

      if( array_search($event['UID'],$remove_list) === false) {
        $new_vevent[] = $event;
      }
    }

    $this->cal['VEVENT'] = $new_vevent;
  }

  private function writeFixedCal($ics_file) {
    $file = fopen($ics_file, 'w');

    //write headers
    fwrite($file, $this->raw_extra);

    foreach($this->cal['VEVENT'] as $items) {
      fwrite($file, "BEGIN:VEVENT\n");

      foreach($items as $keyword=>$data) {
        fwrite($file, $keyword.':'.$data."\n");
      }
    
      fwrite($file, "END:VEVENT\n");
    }

    fwrite($file, "END:VCALENDAR\n");

    fclose($file);

    return true;
  }
  public function readFromFile($filename) {
    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (stristr($lines[0],'BEGIN:VCALENDAR') === false){
      return false;
    } else {
      foreach ($lines as $line) {
        $line = trim($line);
        $add = $this->split_key_value($line);
        if($add === false){
          $this->add_to_array($type, false, $line);
          continue;
        } 

        list($keyword, $value) = $add;

        if(isset($type) && $type != 'VEVENT' && $line != 'BEGIN:VEVENT' && $line != 'END:VCALENDAR') {
          $this->raw_extra .= $line."\n";
        }
        switch ($line) {
          // http://www.kanzaki.com/docs/ical/vtodo.html
        case "BEGIN:VTODO": 
          $this->todo_count++;
          $type = "VTODO"; 
          break; 

          // http://www.kanzaki.com/docs/ical/vevent.html
        case "BEGIN:VEVENT": 
          #echo "vevent gematcht";
          $this->event_count++;
          $type = "VEVENT"; 
          break; 

          //all other special strings
        case "BEGIN:VCALENDAR": 
        case "BEGIN:DAYLIGHT": 

          // http://www.kanzaki.com/docs/ical/vtimezone.html
        case "BEGIN:VTIMEZONE": 
        case "BEGIN:STANDARD": 
          $type = $value;
          break; 
        case "END:VTODO": // end special text - goto VCALENDAR key 
        case "END:VEVENT": 
        case "END:VCALENDAR": 
        case "END:DAYLIGHT": 
        case "END:VTIMEZONE": 
        case "END:STANDARD": 
          $type = "VCALENDAR"; 
          break; 
        default:
          $this->add_to_array($type, $keyword, $value);
          break; 
        } 
      }
      return $this->cal; 
    }
  }

  /** 
   * Add to $this->ical array one value and key.
   * 
   * @param {string} $type This could be VTODO, VEVENT, VCALENDAR, ... 
   * @param {string} $keyword
   * @param {string} $value 
   */ 
  function add_to_array($type, $keyword, $value) {
    if ($keyword == false) { 
      $keyword = $this->last_keyword; 
      switch ($type) {
      case 'VEVENT': 
        $value = $this->cal[$type][$this->event_count - 1][$keyword].$value;
        break;
      case 'VTODO' : 
        $value = $this->cal[$type][$this->todo_count - 1][$keyword].$value;
        break;
      }
    }

    if (stristr($keyword,"DTSTART") or stristr($keyword,"DTEND")) {
      $keyword = explode(";", $keyword);
      $keyword = $keyword[0];
    }

    switch ($type) { 
    case "VTODO": 
      $this->cal[$type][$this->todo_count - 1][$keyword] = $value;
      #$this->cal[$type][$this->todo_count]['Unix'] = $unixtime;
      break; 
    case "VEVENT": 
      $this->cal[$type][$this->event_count - 1][$keyword] = $value; 
      break; 
    // default: 
    //   $this->cal[$type][$keyword] = $value; 
    //   break; 
    } 
    $this->last_keyword = $keyword; 
  }

  /**
   * @param {string} $text which is like "VCALENDAR:Begin" or "LOCATION:"
   * @return {Array} array("VCALENDAR", "Begin")
   */
  function split_key_value($text) {
    preg_match("/([^:]+)[:]([\w\W]*)/", $text, $matches);
    if(count($matches) == 0){return false;}
    $matches = array_splice($matches, 1, 2);
    return $matches;
  }

}
