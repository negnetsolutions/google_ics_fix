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
    $this->writeFixedCal($ics_file.'_fixed');

    return true;

  }

  private function fixRepeatingUids()
  {
    $fix_list = array();
    $revision_stamps = array();

    if(!isset($this->cal['VEVENT']) || count($this->cal['VEVENT']) == 0) {
      return;
    }

    foreach($this->cal['VEVENT'] as $event) {

      if( preg_match("/_R[0-9]{8}T[0-9]{6}/u", $event['UID'], $matches) ) {
        // looks like we found a possible hit for this hack
        // check to see if another event has the naturalized uid or an older
        // revision. If so we want to find the older one and make sure that 
        // the UNTIL is set a day before this event starts.

        $naturalized_uid = str_replace($matches[0],'',$event['UID']);
        $revision_stamp = substr($matches[0],2);
        $revision_dt = new DateTime($revision_stamp);
        $revision_timestamp = $revision_dt->getTimestamp();

        $keys = array_keys($event);
        $dtstart = 0;
        foreach($keys as $keyword) {
          if(strstr($keyword,'DTSTART')){
            $dtstart = $event[$keyword];
          }
        }

        $dt = new DateTime($dtstart);
        $dt = new DateTime($dt->format('Ymd'));
        $dt->modify('-1 second');

        // $dt = new DateTime($dtstart);
        // $dt->modify('-1 second');
        //set new until to 1 second before start of next revised
        //event.
        $new_until = $dt->format('Ymd\THis');
        $revision_stamps[] = $revision_timestamp;
        $fix_list[] = array('uid'=>$naturalized_uid,'until'=>$new_until,'revision_ts'=>$revision_timestamp,'dtstart'=>$dtstart);

      }
    }

    //sort fix list by revision date DESC so we can iterate through changing 
    //each revision as we go
    array_multisort($revision_stamps, SORT_DESC, $fix_list);

    $prune_list = array();
    foreach($fix_list as $fix) {
    
      //iterate through events looking for matching naturalized uids with 
      //revisions before current revision
      foreach($this->cal['VEVENT'] as $i => $event) {

        if( preg_match("/(".strstr($fix['uid'],'@',true).")(_R[0-9]{8}T[0-9]{6})*/u", $event['UID'], $matches) ) {

          $revision_ts = 0;
          if( isset($matches[2]) ){ //event has a revision, get revision timestamp
            $dt = new DateTime(substr($matches[2],2));
            $revision_ts = $dt->getTimestamp();
          }

          if( $fix['revision_ts'] > $revision_ts ) { //event until needs to be updated
            //check if the event's DTSTART is the same. If so, prune the 
            //event. Otherwise fix the RRULE
            $keys = array_keys($event);
            $dtstart = 0;
            foreach($keys as $keyword) {
              if(strstr($keyword,'DTSTART')){
                $dtstart = $event[$keyword];
              }
            }

            if( $dtstart == $fix['dtstart'] ){
              $prune_list[] = $event['UID'];
            }
            else if(isset($event['RRULE'])) { //make sure event has RRULE
              preg_match("/UNTIL=[0-9]{8}T[0-9]{6}Z/u", $event['RRULE'], $matches);

              if( isset($matches[0]) ){
                //update the RRULE with the new fixed UNTIL
                $this->cal['VEVENT'][$i]['RRULE'] = str_replace($matches[0],'UNTIL='.$fix['until'].'Z',$event['RRULE']);
              }
            }

          }
        }
      }
    }

    $new_vevent = array();
    foreach( $this->cal['VEVENT'] as $event ){
      //prune bad events
      if(array_search($event['UID'],$prune_list) === false){
        $new_vevent[] = $event;
      }
    
    }

    $this->cal['VEVENT'] = $new_vevent;
  }

  private function writeFixedCal($ics_file) {
    $file = fopen($ics_file, 'w');

    //write headers
    fwrite($file, "BEGIN:VCALENDAR\n");
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
        $value = $this->cal[$type][$this->event_count - 1][$keyword]."".$value;
        break;
      case 'VTODO' : 
        $value = $this->cal[$type][$this->todo_count - 1][$keyword]."".$value;
        break;
      }
    }

    // if (stristr($keyword,"DTSTART") or stristr($keyword,"DTEND")) {
    //   $keyword = explode(";", $keyword);
    //   $keyword = $keyword[0];
    // }

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
