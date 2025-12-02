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
        header('Content-Type: application/json'); // Ensure JSON response

        try {
            // Resolve service account JSON path
            $path = getenv('GOOGLE_SA_JSON');
            if (!$path || !file_exists($path)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Service account JSON file not found at ' . (__DIR__ . '/../../crack-audio-480009-k7-b5f20f881669.json')
                ]);
                return;
            }

            // Initialize Google Client
            $client = new Google_Client();
            $client->setApplicationName("RideZones Dashboard");
            $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
            
            // Load service account config
            $jsonContent = file_get_contents($path);
            $config = json_decode($jsonContent, true);

            if (!$config) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to parse service account JSON file. Check if it is valid.'
                ]);
                return;
            }

            $client->setAuthConfig($config);

            // Initialize Sheets service
            $service = new Google_Service_Sheets($client);

            // Spreadsheet ID and range
            $spreadsheetId = "1tYHdRF57htp3EskNYdkzUHN_M_2sCTuUoxMc-VWkl-I";
            $range = "Form Responses 1!A:Z";

            // Fetch values
            $response = $service->spreadsheets_values->get($spreadsheetId, $range);
            $values = $response->getValues();

            if (empty($values)) {
                echo json_encode([
                    "success" => true,
                    "data" => []
                ]);
                return;
            }

            // Convert rows to associative array using header row
            $headers = array_shift($values);
            $data = [];

            foreach ($values as $row) {
                $row = array_pad($row, count($headers), null);
                $data[] = array_combine($headers, $row);
            }

            echo json_encode([
                "success" => true,
                "data" => $data
            ]);
            return;

        } catch (\Exception $e) {
            // Catch any exceptions and return JSON
            echo json_encode([
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ]);
            return;
        }
    }

}