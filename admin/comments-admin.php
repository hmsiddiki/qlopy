<?php
// Admin AJAX handlers for comment moderation
if (!function_exists('add_admin_action')) return;

add_admin_action('iitcm_admin_ajax_comment_approve', function($req){
	header('Content-Type: application/json');
	if (!is_logged_in() || !current_user_can('manage_posts') || !current_user_can('moderate_comments')) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
	$id = intval($req['comment_id'] ?? 0);
	if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'comment_id required']); exit; }
	if (!function_exists('comment_update_status')) { echo json_encode(['status'=>'error','message'=>'no_comment_api']); exit; }
	$ok = comment_update_status($id, 'approved', qp_current_user_id());
	echo json_encode(['status'=>$ok ? 'success' : 'error']); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_comment_mark_spam', function($req){
	header('Content-Type: application/json');
	if (!is_logged_in() || !current_user_can('manage_posts') || !current_user_can('moderate_comments')) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
	$id = intval($req['comment_id'] ?? 0);
	if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'comment_id required']); exit; }
	if (!function_exists('comment_mark_spam')) { echo json_encode(['status'=>'error','message'=>'no_comment_api']); exit; }
	$ok = comment_mark_spam($id);
	echo json_encode(['status'=>$ok ? 'success' : 'error']); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_comment_delete', function($req){
	header('Content-Type: application/json');
	if (!is_logged_in() || !current_user_can('manage_posts') || !current_user_can('moderate_comments')) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
	$id = intval($req['comment_id'] ?? 0);
	if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'comment_id required']); exit; }
	if (!function_exists('comment_delete')) { echo json_encode(['status'=>'error','message'=>'no_comment_api']); exit; }
	$ok = comment_delete($id);
	echo json_encode(['status'=>$ok ? 'success' : 'error']); exit;
}, 10, 1);

