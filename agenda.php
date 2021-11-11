<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Sabre\VObject;

require "CalDAVClient.php";

class NotImplementedException extends BadMethodCallException {}

require __DIR__ . '/vendor/autoload.php';

class Agenda {
    
    private $caldav_client;
    protected $localcache;
    
    public function __construct($url, $username, $password, Localcache $localcache) {
        $this->log = new Logger('Agenda');
        $this->log->pushHandler(new StreamHandler('access.log', Logger::DEBUG));
        $this->caldav_client = new CalDAVClient($url, $username, $password);
        $this->localcache = $localcache;
    }

    function getUserEventsFiltered($userid, $api, $filters_to_apply = array()) {
        $parsed_events = array();
        
        foreach($this->localcache->getAllEventsNames() as $filename) {
            $event = $this->getEvent($filename);
            $parsed_event = $this->parseEvent($userid, $event, $api);
            
            $parsed_event["keep"] = true;
            
            if(count($filters_to_apply) >= 0) {
                foreach($filters_to_apply as $filter) {
                    if($filter === "my_events") {
                        if(!$parsed_event["is_registered"]) {
                            $parsed_event["keep"] = false;
                            break;
                        }
                    } else if(!is_nan(is_level_category($filter))) {
                        if($filter !== "E{$parsed_event["level"]}") {
                            $parsed_event["keep"] = false;
                            break;
                        }
                    } else if($filter === "need_volunteers") {
                        if(is_nan($parsed_event["participant_number"]) or
                           count($parsed_event["attendees"]) >= $parsed_event["participant_number"]) {
                            $parsed_event["keep"] = false;
                            break;
                        }
                    } else if(!in_array($filter, $parsed_event["categories"])) {
                        $parsed_event["keep"] = false;
                        break;
                    }
                }            
                //if($parsed_event["keep"] == false) {
                //return $parsed_event; // no need to process render
                //}
            }
            $parsed_events[$filename] = $parsed_event;
        }
        return $parsed_events;
    }

    function parseEvent($userid, $event, $api) {
        $parsed_event = array();
        $parsed_event["vcal"] = $event;
        $parsed_event["is_registered"] = false;
        $parsed_event["attendees"] = array();
        $parsed_event["categories"] = array();
        
        if(isset($event->VEVENT->ATTENDEE)) {
            foreach($event->VEVENT->ATTENDEE as $attendee) {
                $a = [
                    //"cn" => $attendee['CN']->getValue(),
                    "mail" => str_replace("mailto:", "", (string)$attendee)
                ];
                
                $a["userid"] = $api->users_lookupByEmail($a["mail"])->id;
                
                $parsed_event["attendees"][] = $a;
                if($a["userid"] == $userid) {
                    $parsed_event["is_registered"] = true;
                }
            }
        }
        
        $parsed_event["level"] = NAN;
        $parsed_event["participant_number"] = NAN;
        
        if(isset($event->VEVENT->CATEGORIES)) {
            foreach($event->VEVENT->CATEGORIES as $category) {
                //preg_match_all(slackEvents::$regex_number_attendee, $category, $matches_number_attendee, PREG_SET_ORDER, 0);
                //preg_match_all(slackEvents::$regex_level, $category, $matches_level, PREG_SET_ORDER, 0);
                
                if(is_nan($parsed_event["level"]) and
                   !is_nan($parsed_event["level"] = is_level_category((string)$category))) {
                    continue;
                }
                
                if(is_nan($parsed_event["participant_number"]) and
                   !is_nan($parsed_event["participant_number"] = is_number_of_attendee_category((string)$category))) {
                    continue;
                }
                //$filters[] = (string)$category;
                $parsed_event["categories"][] = (string)$category;
            }
        }
        return $parsed_event;
    }


    function getEvents() {
        $events = array();
        foreach($this->localCache->getEvents() as $eventName => $serializedEvent){
            $vcal = \Sabre\VObject\Reader::read($serializedEvent);
            $startDate = $vcal->VEVENT->DTSTART->getDateTime();
            
            if($startDate < new DateTime('NOW')) {
                $this->log->debug("Event is in the past, skiping");
                continue;
            }
            $events[$eventName] = $vcal;
        }    
        uasort($events, function ($v1, $v2) {
            return $v1->VEVENT->DTSTART->getDateTime() > $v2->VEVENT->DTSTART->getDateTime();
        });
        
        return $events;
    }
    
    // update agenda
    function update() {
        $remote_ctag = $this->caldav_client->getctag();
        
        // check if we need to update events from the server
        $local_ctag = $this->localcache->getctag();
        $this->log->debug("ctags", ["remote" => $remote_ctag, "local" => $local_ctag]);
        if (is_null($local_ctag) || $local_ctag != $remote_ctag){
            $this->log->debug("Agenda update needed");
            $remote_etags = $this->caldav_client->getetags();
            $this->updateInternalState($remote_etags);
            $this->localcache->setctag($remote_ctag);
        }
    }

    // 
    protected function updateInternalState($etags) {
        $url_to_update = [];
        foreach($etags as $url => $remote_etag) {
            $tmp = explode("/", $url);
            $eventName = end($tmp);
            if($this->localcache->eventExists($eventName)) {
                $local_etag = $this->localcache->getEventEtag($eventName);
                $this->log->debug(end($tmp), ["remote_etag"=>$remote_etag, "local_etag" => $local_etag]);
                
                if($local_etag != $remote_etag) {
                    // local and remote etag differs, need update
                    $url_to_update[] = $url;
                }
            } else {
                $url_to_update[] = $url;
            }
        }
        
        if(count($url_to_update) > 0) {
            $this->updateEvents($url_to_update);
        }
        
        $this->removeDeletedEvents($etags);
    }
    
    // delete local events that have been deleted on the server
    protected function removeDeletedEvents($etags) {
        $eventNames = [];
        foreach($etags as $url => $etag) {
            $eventNames[] = basename($url);
        }
        
        foreach($this->localcache->getAllEventsNames() as $eventName){
            if(in_array($eventName, $eventNames)) {
                $this->log->debug("No need to remove ". $eventName);
            } else {
                $this->log->info("Need to remove ". $eventName);
                $this->localcache->deleteEvent($eventName);
            }
        }
    }

    private function updateEvents($urls) {
        $xml = $this->caldav_client->updateEvents($urls);
        
        foreach($xml as $event) {
            if(isset($event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data'])) {
                $eventName = basename($event['value']['href']);
                $this->log->info("Adding event " . $eventName);
                
                $this->localcache->deleteEvent($eventName);
                
                
                // parse event to get its DTSTART
                $vcal = \Sabre\VObject\Reader::read($event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data']);
                $startDate = $vcal->VEVENT->DTSTART->getDateTime();
                
                if($startDate < new DateTime('NOW')) {
                    $this->log->debug("Event is in the past, skiping");
                    continue;
                }
                
                $this->localcache->addEvent($eventName, $event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data'], trim($event['value']['propstat']['prop']['getetag'], '"'));
            }
        }
    }
    
    //if add is true, then add $usermail to the event, otherwise, remove it.
    function updateAttendee($url, $usermail, $add, $attendee_CN=NULL) {
        $raw = $this->localcache->getSerializedEvent($url);
        $etag = $this->localcache->getEventEtag($url);
        
        $vcal = \Sabre\VObject\Reader::read($raw);
        
        if($add) {
            if(isset($vcal->VEVENT->ATTENDEE)) {
                foreach($vcal->VEVENT->ATTENDEE as $attendee) {
                    if(str_replace("mailto:","", (string)$attendee) === $usermail) {
                        $this->log->info("Try to add a already registered attendee");
                        return;
                    }
                }
            }
            
            /*$vcal->VEVENT->add(
              'ATTENDEE',
              'mailto:' . $usermail,
              [
              'RSVP' => 'TRUE',
              'CN'   => (is_null($attendee_CN)) ? 'Bénévole' : $attendee_CN, //@TODO
              ]
              );*/
            $vcal->VEVENT->add('ATTENDEE', 'mailto:' . $usermail);
        } else {
            $already_out = true;
            
            if(isset($vcal->VEVENT->ATTENDEE)) {
                foreach($vcal->VEVENT->ATTENDEE as $attendee) {
                    if(str_replace("mailto:","", (string)$attendee) === $usermail) {
                        $vcal->VEVENT->remove($attendee);
                        $already_out = false;
                        break;
                    }
                }
            }
            
            if($already_out) {
                $this->log->info("Try to remove an unregistered email");
                return;
            }
        }
        
        $new_etag = $this->caldav_client->updateEvent($url, $etag, $vcal->serialize());
        
        $this->log->debug($vcal->serialize());
        if(is_null($new_etag)) {
            $this->log->info("The server did not answer a new etag after an event update, need to update the local calendar");
            $this->updateEvents(array($url));
        } else {
            $this->localcache->addEvent($url, $vcal->serialize(), $new_etag);
        }
    }

    function getEvent($url) {
        $raw = $this->localcache->getSerializedEvent($url);
        if(!is_null($raw)) {
            return \Sabre\VObject\Reader::read($raw);
        }
    	return null;
    }
    
    protected function clearEvents() {
        throw new NotImplementedException();
    }
}
