<?php

// function wp_r2_upload_file($file) {
//     error_log("[R2 UPLOAD] Start upload file: " . $file['name']);

//     $content = file_get_contents($file['tmp_name']);
//     $key = basename($file['name']);

//     $res = wp_r2_request('PUT', '/' . $key, $content, [
//         'content-type' => mime_content_type($file['tmp_name']),
//     ]);

//     error_log("[R2 UPLOAD] Response status: " . $res['status']);
//     error_log("[R2 UPLOAD] Response body: " . substr($res['body'], 0, 500));

//     if ($res['status'] == 200) {
//         error_log("[R2 UPLOAD] SUCCESS: " . $res['url']);
//         return ['url' => $res['url'], 'key' => $key];
//     }
//     error_log("[R2 UPLOAD] FAILED: " . json_encode($res));
//     return ['error' => $res['body']];
// }

// function wp_r2_upload_base64($base64) {
//     error_log("[R2 UPLOAD] Start upload base64...");

//     $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64));
//     $key = 'img_' . time() . '.png';

//     $res = wp_r2_request('PUT', '/' . $key, $data, [
//         'content-type' => 'image/png',
//     ]);

//     error_log("[R2 UPLOAD] Response status: " . $res['status']);
//     error_log("[R2 UPLOAD] Response body: " . substr($res['body'], 0, 500));

//     if ($res['status'] == 200) {
//         error_log("[R2 UPLOAD] SUCCESS: " . $res['url']);
//         return ['url' => $res['url'], 'key' => $key];
//     }
//     error_log("[R2 UPLOAD] FAILED: " . json_encode($res));
//     return ['error' => $res['body']];
// }
