<?php
/* $Id$ */
include_once 'includes/init.php';

if ( ! $is_admin ) {
  $error = 'You are not authorized';
} else  {
  $delete = getPostValue ( 'delete' );
  if ( ! empty ( $delete ) ) {
    // delete this group
    dbi_execute ( 'DELETE FROM webcal_group WHERE cal_group_id = ? ', array( $id ) );
    dbi_execute ( 'DELETE FROM webcal_group_user WHERE cal_group_id = ? ', array( $id ) );
  } else {
    $date = date ( 'Ymd' );
    if ( empty ( $groupname ) ) {
      $error = translate( 'You must specify a group name' );
    }
    else if ( ! empty ( $id ) ) {
      # update
      if ( ! dbi_execute ( 'UPDATE webcal_group SET cal_name = ' .
        '?, cal_last_update = ? ' .
        'WHERE cal_group_id = ?', array( $groupname, $date, $id ) ) ) {
        $error = db_error ();
      }
    } else {
      # new... get new id first
      $res = dbi_execute ( 'SELECT MAX(cal_group_id) FROM webcal_group' );
      if ( $res ) {
        $row = dbi_fetch_row ( $res );
        $id = $row[0];
        $id++;
        dbi_free_result ( $res );
        $sql = 'INSERT INTO webcal_group ' .
          '( cal_group_id, cal_owner, cal_name, cal_last_update ) VALUES ( ?, ?, ?, ? )';
        if ( ! dbi_execute ( $sql, array( $id, $login, $groupname, $date ) ) ) {
          $error = db_error ();
        }
      } else {
        $error = db_error ();
      }
    }
  
    # update user list
    if ( empty ( $error ) &&  ! empty ( $users ) ) {
      dbi_execute ( 'DELETE FROM webcal_group_user WHERE cal_group_id = ?', array( $id ) );
      for ( $i = 0, $cnt = count ( $users ); $i < $cnt ; $i++ ) {
        dbi_execute ( 'INSERT INTO webcal_group_user ( cal_group_id, cal_login ) ' .
          'VALUES ( ?, ? )', array( $id, $users[$i] ) );
      }
    }
  }
}

echo error_check('users.php', false); 
?>
