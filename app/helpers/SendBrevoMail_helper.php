<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Helper: SendBrevoMail_helper.php
 * 
 * Automatically generated via CLI.
 */

function sendBrevoEmail($toEmail, $toName, $subject, $htmlContent)
{
   $apiKey = getenv('BREVO_API_KEY') ?: ($_ENV['BREVO_API_KEY'] ?? null);

     if (!$apiKey) {
         error_log("[Brevo] BREVO_API_KEY not configured!");
         return false;
     }

     $payload = [
         "sender"      => ["name" => "RIDEZONE", "email" => "johnrheynedamotamares2005@gmail.com"],
         "to"          => [["email" => $toEmail, "name" => $toName]],
         "subject"     => $subject,
         "htmlContent" => $htmlContent
     ];

     $ch = curl_init();
     curl_setopt($ch, CURLOPT_URL, "https://api.brevo.com/v3/smtp/email");
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
     curl_setopt($ch, CURLOPT_POST, true);
     curl_setopt($ch, CURLOPT_HTTPHEADER, [
         "accept: application/json",
         "api-key: {$apiKey}",
         "content-type: application/json"
     ]);
     curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

     $response = curl_exec($ch);
     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
     curl_close($ch);

     if ($httpCode === 201) {
         error_log("[Brevo] Email sent → {$toEmail} | Subject: {$subject}");
         return true;
     }

     error_log("[Brevo] Failed ({$httpCode}) → {$toEmail} | Response: {$response}");
     return false;
 
}
