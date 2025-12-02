<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: GoogleFormController
 * 
 * Automatically generated via CLI.
 */

use Google_Client;
use Google_Service_Sheets;

class GoogleFormController extends Controller {
    public function __construct()
    {
        parent::__construct();
    }

    public function getResponses()
    {

        $client = new Google_Client();
        $client->setApplicationName("RideZones Dashboard");
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig(__DIR__ . '..\..\crack-audio-480009-k7-b5f20f881669.json');

        $service = new Google_Service_Sheets($client);

        // ID of the Google Sheet linked to your Google Form
        $spreadsheetId = "1tYHdRF57htp3EskNYdkzUHN_M_2sCTuUoxMc-VWkl-I";

        // Name of the sheet (usually "Form Responses 1")
        $range = "Form Responses 1!A:Z";

        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();

        if (empty($values)) {
            return json_encode([
                "success" => true,
                "data" => []
            ]);
        }

        // Convert rows to associative format
        $headers = array_shift($values);  // first row = header row
        $data = [];

        foreach ($values as $row) {
            // Fill missing columns with null
            $row = array_pad($row, count($headers), null);
            $data[] = array_combine($headers, $row);
        }

        echo json_encode([
            "success" => true,
            "data" => $data
        ]);
    }
}