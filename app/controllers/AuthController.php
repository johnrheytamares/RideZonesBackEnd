<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use Google\Client as GoogleClient;

class AuthController extends Controller {

public function googleCallback()
{
    while (ob_get_level()) ob_end_clean();

    header('Access-Control-Allow-Origin: https://ride-zones-front-end-liard.vercel.app');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['credential'] ?? null;

    if (!$token) {
        echo json_encode(['success' => false, 'error' => 'No token']);
        exit;
    }

    try {
        $client = new Google\Client();
        $client->setClientId('1084979266133-d1bvpmpb5devqn5cl0pscuv9k01l9p9t.apps.googleusercontent.com');

        $payload = $client->verifyIdToken($token);

        if (!$payload) {
            echo json_encode(['success' => false, 'error' => 'Invalid token']);
            exit;
        }

        $googleId = $payload['sub'];
        $email    = $payload['email'];
        $name     = $payload['name'] ?? 'User';
        $picture  = $payload['picture'] ?? null;

        // Check existing account
$query = $this->db
    ->table('users')
    ->where('google_id', $googleId)
    ->or_where('email', $email)
    ->get(); // executes the query

$user = $query[0] ?? null; // get the first row if exists




        if (!$user) {

            // Generate unique name
            $uniqueName = $this->generateUniqueUsername($name, $email);

            $this->db->table('users')->insert([
                'name'      => $uniqueName,
                'email'     => $email,
                'google_id' => $googleId,
                'avatar'    => $picture,
                'role'      => 'dealer',
                'email_verified_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $userId = $this->db->insert_id();

            $user = [
                'id'        => $userId,
                'name'      => $uniqueName,
                'email'     => $email,
                'role'      => 'dealer',
                'google_id' => $googleId,
                'avatar'    => $picture
            ];
        } else {
            if (empty($user['google_id'])) {
                $this->db->table('users')
                    ->where('id', $user['id'])
                    ->update(['google_id' => $googleId]);
            }
        }

        // Save session
        $this->setUserSession($user);

        echo json_encode([
            'success' => true,
            'user' => [
                'id'     => $user['id'],
                'name'   => $user['name'],
                'email'  => $user['email'],
                'role'   => $user['role'],
                'avatar' => $user['avatar'] ?? null
            ]
        ]);

    } catch (Exception $e) {
        error_log('Google Login Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Authentication failed']);
    }
    exit;
}

private function setUserSession($user)
{
    if (!isset($this->session))
        $this->load->library('session');

    $this->session->set_userdata([
        'user_id'   => $user['id'],
        'name'      => $user['name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
        'logged_in' => true
    ]);
}

    // ================== HELPER METHODS ==================
    private function generateUniqueUsername($name, $email)
    {
        $base = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($name ?: substr($email, 0, strpos($email, '@'))));
        $base = substr($base ?: 'user', 0, 20);
        $name = $base;
        $i = 1;

        while ($this->db->table('users')->where('name', $name)->get()) {
            $name = substr($base, 0, 16) . $i++;
        }
        return $name;
    }


    public function logout()
    {
        $this->session->sess_destroy();
        redirect('/');
    }
}