<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use Google\Client as GoogleClient;

class AuthController extends Controller {

public function googleCallback()
{


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

        if ($this->DEV_MODE) {

            // BYPASS USER
            $fakeUser = [
                'id' => 23,
                'name' => 'admin',
                'email' => 'johnrheynedamotamares2005@gmail.com',
                'role' => 'admin',
                'dealer_id' => 1,
                'avatar' => null
            ];

            $this->setUserSession($fakeUser);

            echo json_encode([
                'success' => true,
                'user' => $fakeUser,
                'dev_mode' => true
            ]);
            exit;
        }

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

    public function forgotPassword()
{
    $this->api->require_method('POST');
    $input = $this->api->body();

    $email = trim($input['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->api->respond_error('Valid email is required', 400);
    }

    // Check if user exists
    $stmt = $this->db->raw("SELECT id, name FROM users WHERE email = ?", [$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // ALWAYS return same message (security best practice)
    $responseMessage = 'If the email exists, a password reset link has been sent.';

    if (!$user) {
        return $this->api->respond(['message' => $responseMessage]);
    }

    // Delete old tokens
    $this->db->raw("DELETE FROM password_reset_tokens WHERE email = ?", [$email]);

    // Generate secure token (60 chars)
    $token = bin2hex(random_bytes(30)); // 60 characters, super secure

    // Save token (hashed for security)
    $hashedToken = password_hash($token, PASSWORD_DEFAULT);

    $this->db->raw("
        INSERT INTO password_reset_tokens (email, token, created_at) 
        VALUES (?, ?, NOW())
    ", [$email, $hashedToken]);

    // Send email
    $this->AuthController->sendPasswordResetEmail($user['name'], $email, $token);

    return $this->api->respond(['message' => $responseMessage]);
}

// ===================================================================
// RESET PASSWORD — Full working version
// ===================================================================
public function resetPassword()
{
    $this->api->require_method('POST');
    $input = $this->api->body();

    $email = trim($input['email'] ?? '');
    $token = $input['token'] ?? '';
    $password = $input['password'] ?? '';
    $password_confirm = $input['password_confirmation'] ?? '';

    if (!$email || !$token || !$password || $password !== $password_confirm) {
        return $this->api->respond_error('Invalid or incomplete data', 400);
    }

    if (strlen($password) < 8) {
        return $this->api->respond_error('Password must be at least 8 characters', 400);
    }

    // Get reset token from DB
    $stmt = $this->db->raw("
        SELECT token, created_at FROM password_reset_tokens 
        WHERE email = ? ORDER BY created_at DESC LIMIT 1
    ", [$email]);

    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset) {
        return $this->api->respond_error('Invalid or expired reset link', 400);
    }

    // Check if token is older than 60 minutes
    $createdAt = new DateTime($reset['created_at']);
    $now = new DateTime();
    $diff = $now->diff($createdAt)->i;

    if ($diff > 60) {
        $this->db->raw("DELETE FROM password_reset_tokens WHERE email = ?", [$email]);
        return $this->api->respond_error('Reset link has expired', 400);
    }

    // Verify token
    if (!password_verify($token, $reset['token'])) {
        return $this->api->respond_error('Invalid token', 400);
    }

    // Update password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $this->db->raw("UPDATE users SET password_hash = ? WHERE email = ?", [$hashedPassword, $email]);

    // Delete all tokens for this email
    $this->db->raw("DELETE FROM password_reset_tokens WHERE email = ?", [$email]);

    return $this->api->respond([
        'status' => 'success',
        'message' => 'Password reset successful!'
    ]);
}
}