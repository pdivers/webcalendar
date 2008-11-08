<?php
/* $Id$
 *
 * Description
 *   Handler for AJAX requests for viewing events in the
 *   Day/Week/Month/Year views.
 *
 *   We use JSON for some of the data we send back to the AJAX request.
 *   Because JSON support was not built-in to PHP until 5.2, we have our
 *   own implmentation in includes/JSON.php.
 *
 *   Most of the event handling is identical to the non-AJAX PHP pages except
 *   that we store the local user's version of each event's date and time
 *   in the Event and RptEvent classes.
 */
include_once 'includes/translate.php';
require_once 'includes/classes/WebCalendar.class';
require_once 'includes/classes/Event.class';
require_once 'includes/classes/RptEvent.class';

$WebCalendar =& new WebCalendar ( __FILE__ );

include 'includes/config.php';
include 'includes/dbi4php.php';
include 'includes/formvars.php';
include 'includes/functions.php';

$WebCalendar->initializeFirstPhase ();

include 'includes/' . $user_inc;
include 'includes/access.php';
include 'includes/validate.php';
include 'includes/JSON.php';
include 'includes/ajax.php';

// Load Doc classes for attachments and comments
include 'includes/classes/Doc.class';
include 'includes/classes/DocList.class';
include 'includes/classes/AttachmentList.class';
include 'includes/classes/CommentList.class';

$WebCalendar->initializeSecondPhase ();

load_global_settings ();
load_user_preferences ();
$WebCalendar->setLanguage ();

load_user_layers ();

$debug = getValue ( 'debug' );
$debug = ! empty ( $debug );
$action = getValue ( 'action' );
if ( empty ( $action ) )
  $action = 'get';
// $user will be set in WebCalendar.class
if ( empty ( $user ) )
  $user = $login;
$get_unapproved = true;

$startdate = getIntValue ( 'startdate' );
if ( empty ( $startdate ) )
  $startdate = date ( "Ym" ) . '01';
$startyear = substr ( $startdate, 0, 4 );
$startmonth = substr ( $startdate, 4, 2 );
$startday = substr ( $startdate, 6, 2 );
$startTime = mktime ( 3, 0, 0, $startmonth, $startday, $startyear );
$enddate = getIntValue ( 'enddate' );
if ( empty ( $enddate ) )
  $enddate = date ( "Ymd", mktime ( 3, 0, 0, $startmonth + 1,
    $startday, $startyear ) );
$endyear = substr ( $enddate, 0, 4 );
$endmonth = substr ( $enddate, 4, 2 );
$endday = substr ( $enddate, 6, 2 );
$endTime = mktime ( 3, 0, 0, $endmonth, $endday, $endyear );

$error = '';

if ( $is_admin && ! empty ( $public ) && $PUBLIC_ACCESS == 'Y' ) {
  $updating_public = true;
  $layer_user = '__public__';
} else {
  $layer_user = $login;
}

if ( $action == 'get' ) {
  $dates = array ();
  $eventCats = array ();
//echo "startdate: $startdate <br>enddate: $enddate<br>";
  /* Pre-Load the repeated events for quicker access */
  $repeated_events = read_repeated_events ( $user, $startTime, $endTime );
  /* Pre-load the non-repeating events for quicker access */
  $events = read_events ( $user, $startTime, $endTime );
  $tasks = array ();
  if ( $DISPLAY_TASKS_IN_GRID == 'Y' )
    $tasks = read_tasks ( $user, $enddate );
  // Gather the category IDs for each
  $ids = array ();
  for ( $i = 0; $i < count ( $events ); $i++ ) {
    $id = $events[$i]->getID ();
    $ids[$id] = $id;
  }
  for ( $i = 0; $i < count ( $repeated_events ); $i++ ) {
    $id = $repeated_events[$i]->getID ();
    $ids[$id] = $id;
  }
  for ( $i = 0; $i < count ( $tasks ); $i++ ) {
    $id = $tasks[$i]->getID ();
    $ids[$id] = $id;
  }
  // Load all category IDs for the specified event IDs
  // echo "<pre>"; print_r ( $ids ); echo "</pre>";
  load_category_ids ( $ids );

  // TODO:  We need to be able to start a week on ANY day.
  $wkstart = get_weekday_before ( $startyear, $startmonth );
  $monthend = date ( 'Ymd',
    mktime ( 0, 0, 0, $startmonth + 1, 0, $startyear ) );
  for ( $i = $wkstart; date ( 'Ymd', $i ) <= $monthend; $i += 604800 ) {
    $tmp = $i + 172800; // 48 hours.
    for ( $j = 0; $j < 7; $j++ ) {
      // Add 12 hours just so we don't have DST problems.
      $date = $i + ( $j * 86400 + 43200 );
      $dateYmd = date ( 'Ymd', $date );
      $myEvents = get_entries ( $dateYmd, $get_unapproved );
      $myRepEvents =  get_repeating_entries ( $user, $dateYmd );
      $ev = combine_and_sort_events ( $myEvents, $myRepEvents );
      setLocalTimes ( $ev );
      setCategories ( $ev );
      //echo "<pre>"; print_r ( $ev ); echo "</pre>\n";
      $dates[$dateYmd] = $ev;
    }
  }
  $objects = array ( 'dates' => $dates );
  if ( $debug ) {
    echo "<pre>"; print_r ( $objects ); echo "</pre>\n";
  }
  ajax_send_objects ( $objects, true );
} else if ( $action == 'eventinfo' ) {
  // TODO: enforce user access control here...
  $id = getIntValue ( 'id' );
  $res = dbi_execute ( 'SELECT cal_login, cal_status ' .
    'FROM webcal_entry_user WHERE cal_id = ?', array ( $id ) );
  $parts = array ();
  $comments = array ();
  $attachments = array ();
  if ( ! $res ) {
    $error = translate("Database error") . ': ' . dbi_error ();
  } else {
    while ( $row = dbi_fetch_row ( $res ) ) {
      $parts[] = array ( 'login' => $row[0],
         'status' => $row[1] );
    }
    dbi_free_result ( $res );
  }
  // Get list of attachments.
  if ( Doc::attachmentsEnabled () ) {
    $attList =& new AttachmentList ( $id );
    for ( $i = 0; $i < $attList->getSize (); $i++ ) {
      $a = $attList->getDoc ( $i );
      // Set link target to '_blank' so that we don't lose our place.
      // If we go to another page, the back button will re-init the page
      // so the user loses his place.
      $attachments[] = array ( 'summary' => $a->getSummary ( '_blank' ),
        'id' => $a->getId (),
        'owner' => $a->getLogin () );
    }
  }
  if ( Doc::commentsEnabled () ) {
    $comList =& new CommentList ( $id );
    $comment_text = '';
    for ( $i = 0; $i < $comList->getSize(); $i++ ) {
      $cmt = $comList->getDoc ( $i );
      $comments[] = array (
        'description' => htmlspecialchars ( $cmt->getDescription() ),
        'owner' => $cmt->getLogin(),
        'datetime' => date_to_str ( $cmt->getModDate (), '', false, true ) . ' '
          . display_time ( $cmt->getModTime (), 2 ),
        'text' => nl2br ( activate_urls (
           htmlspecialchars ( $cmt->getData () ) ) ),
        );
    }
  }
  $objects = array (
    'participants' => $parts,
    'comments' => $comments,
    'attachments' => $attachments,
  );
  if ( empty ( $error ) ) {
    ajax_send_objects ( $objects, true );
  } else {
    ajax_send_error ( translate('Unknown error.') );
  }
} else {
  ajax_send_error ( translate('Unknown error.') );
}

// For each event in our list, also set the local time for the current
// user.  This way, the client-side javascript will not need to worry
// about converting times between timezones.
function setLocalTimes ( $eventList )
{
  for ( $i = 0; $i < count ( $eventList ); $i++ ) {
    $event = $eventList[$i];
    $localDate = date_to_str ( $event->getDate(), '__yyyy__ __mm__ __dd__',
      false );
    $localDate = str_replace ( ' ', '', $localDate );
    $event->setLocalDate ( $localDate );
    if ( $event->getTime() <= 0 ) {
      $event->setLocalTime ( $event->getTime() );
    } else {
      $localTime = display_time ( $event->getDatetime(),
        0, '', '24' );
      $localTime = substr ( $localTime, 0, 2 ) .
        substr ( $localTime, 3, 5 );
      $event->setLocalTime ( $localTime );
    }
  }
}

function setCategories ( $eventList )
{
  global $eventCats;

  for ( $i = 0; $i < count ( $eventList ); $i++ ) {
    $event = $eventList[$i];
    $id = $event->getID ();
    if ( ! empty ( $eventCats[$id] ) ) {
      $event->setCategories ( $eventCats[$id] );
    }
  }
}

// Get all categories for each event.
function load_category_ids ( $ids )
{
  global $eventCats, $user;
  //$ids = array_unique ( sort ( $ids, SORT_NUMERIC ) );
  $sql = 'SELECT cal_id, cat_id FROM webcal_entry_categories ' .
    'WHERE cal_id IN (?) AND cat_owner in (\'' . $user . '\',NULL)';
  $idList = implode ( ",", $ids );
  $res = dbi_execute ( $sql, array ( $idList ) );
  $eventCats = array ();
  if ( $res ) {
    while ( $row = dbi_fetch_row ( $res ) ) {
      $eventId = $row[0];
      $catId = $row[1];
      if ( ! empty ( $eventCats[$eventId] ) && is_array ( $eventCats[$eventId] ) ) {
        $eventCats[$eventId][] = $catId;
      } else {
        $eventCats[$eventId] = array ( $catId );
      }
    }
    dbi_free_result ( $res );
  } else {
    ajax_send_error ( translate('Database error') . ": " . dbi_error () );
    exit;
  }
  //echo "<pre>"; print_r ( $eventCats ); echo "</pre>"; exit;
}

exit;
?>
