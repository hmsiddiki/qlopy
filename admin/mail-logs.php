<?php
// This endpoint has been removed. Use the Mail admin UI or admin AJAX endpoints instead.
http_response_code(410);
echo json_encode(['status' => 'error', 'message' => 'Endpoint disabled']);
exit;
?>
