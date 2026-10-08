<?php
// This endpoint has been disabled. Use the Mail admin UI or admin AJAX endpoints instead.
http_response_code(410);
echo json_encode(['status' => 'error', 'message' => 'Endpoint disabled']);
exit;
