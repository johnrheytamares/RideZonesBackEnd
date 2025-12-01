<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../vendor/autoload.php';
class ApiController extends Controller {
    private $user_id;


//=====================================================================================================================================
//====================================================================================================================================

// ===================================================================
// User Management
// ===================================================================
    public function login() {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';

        $stmt = $this->db->raw('SELECT * FROM users WHERE email = ?', [$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $this->api->respond([
                    'status' => 'success',
                    'user' => [
                        'id'        => $user['id'],
                        'name'      => $user['name'],
                        'email'     => $user['email'],
                        'role'      => $user['role'],
                        'dealer_id' => $user['dealer_id'] ?? null
                    ]
            ]);
        } else {
            $this->api->respond_error('Invalid credentials', 401);
        }
    }

    public function logout() {
        $this->api->require_method('POST');
        $this->api->respond(['message' => 'Logged out successfully']);
        $this->session->sess_destroy();
        redirect('/');
    }

    public function list() {
        $stmt = $this->db->table('users')
                         ->select('id, role, name, email, phone, dealer_id, created_at')
                         ->get_all();
        $this->api->respond($stmt);
    }

    public function create() {
        $input = $this->api->body();

        // Set default values
        $role = $input['role'] ?? 'buyer';          // default role
        $phone = $input['phone'] ?? '0927488292';          // default phone is null
        $dealer_id = $input['dealer_id'] ?? 1;  // default dealer_id is null

        $this->db->raw(
            "INSERT INTO users (role, name, email, password_hash, phone, dealer_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [
                $role,
                $input['name'],
                $input['email'],
                password_hash($input['password'], PASSWORD_BCRYPT),
                $phone,
                $dealer_id
            ]
        );

        $this->api->respond(['message' => 'User created']);
    }

    public function update($id) {
        $input = $this->api->body();
        $this->db->raw("UPDATE users SET role=?, name=?, email=?, phone=?, dealer_id=? WHERE id=?",
            [$input['role'], $input['name'], $input['email'], $input['phone'], $input['dealer_id'], $id]);
        $this->api->respond(['message' => 'User updated']);
    }

        public function delete($id) {
        $this->db->raw("DELETE FROM users WHERE id = ?", [$id]);
        $this->api->respond(['message' => 'User deleted']);
    }

    public function refresh() {
        $this->api->require_method('POST');
        $input = $this->api->body();
        $refresh_token = $input['refresh_token'] ?? '';
        $this->api->refresh_access_token($refresh_token);
    }

// ===============================
// GET BOOKED DATES — ONE APPROVED APPOINTMENT PER DAY ONLY
// ===============================
    public function getBookedDates($car_id)
    {
        $this->api->require_method('GET');

        $carId = (int)$car_id;  // ← direktang gamitin ang $car_id mula sa route!
        
        if ($carId <= 0) {
            return $this->api->respond_error('Invalid car_id', 400);
        }

        try {
            $stmt = $this->db->raw("
                SELECT DATE(appointment_at) as date
                FROM appointments
                WHERE car_id = ?
                AND status = 'approved'
                AND DATE(appointment_at) >= CURDATE()
                GROUP BY DATE(appointment_at)
                HAVING COUNT(*) >= 1
            ", [$carId]);

            $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $bookedDates = array_map(fn($d) => date('Y-m-d', strtotime($d)), $dates);

            return $this->api->respond([
                'status'       => 'success',
                'booked_dates' => $bookedDates
            ]);

        } catch (Exception $e) {
            return $this->api->respond_error('Failed: ' . $e->getMessage(), 500);
        }
    }

    // Helper: Calculate remaining months
    private function getMonthsRemaining($endDate)
    {
        if (!$endDate) return null;
        
        $end = new DateTime($endDate);
        $now = new DateTime();
        
        if ($end < $now) return 'Expired';

        $interval = $now->diff($end);
        $months = $interval->y * 12 + $interval->m;
        
        return $months == 0 ? 'Less than 1 month' : "$months month" . ($months > 1 ? 's' : '');
    }

//=====================================================================================================================================

// ===================================================================
// CARS MANAGEMENT — Dealer sees & edits only his cars
// ===================================================================
    public function createCars() {
        $user = $this->getCurrentUser();
        $input = $this->api->body();

        // Dealer can only add their own cars
        $dealer_id = ($user['role'] === 'dealer') ? $user['dealer_id'] : ($input['dealer_id'] ?? 1);

        // Auto-calculate warranty_end_date if warranty_period & start_date provided
        $warranty_end_date = null;
        if (!empty($input['warranty_period']) && !empty($input['warranty_start_date'])) {
            $start = new DateTime($input['warranty_start_date']);
            $end = clone $start;
            $end->add(new DateInterval('P' . $input['warranty_period'] . 'M'));
            $warranty_end_date = $end->format('Y-m-d');
        }

        $this->db->raw("INSERT INTO cars (
            dealer_id, make, model, variant, year, type, price, mileage,
            fuel_type, transmission, color, main_image, description, status,
            warranty_period, warranty_start_date, warranty_end_date, service_history
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $dealer_id,
            $input['make'],
            $input['model'],
            $input['variant'] ?? null,
            $input['year'],
            $input['type'] ?? null,
            $input['price'],
            $input['mileage'] ?? null,
            $input['fuel_type'] ?? null,
            $input['transmission'] ?? null,
            $input['color'] ?? null,
            $input['main_image'] ?? null,
            $input['description'] ?? null,
            $input['status'] ?? 'available',
            $input['warranty_period'] ?? null,
            $input['warranty_start_date'] ?? null,
            $warranty_end_date,
            $input['service_history'] ?? null
        ]);

        $this->api->respond([
            'status' => 'success',
            'message' => 'Car added successfully with warranty info!'
        ]);
    }


    // LIST CARS — MAY WARRANTY & SERVICE NA RIN
    public function listCars() {
        $user = $this->getCurrentUser();

        $sql = "SELECT 
                    id, dealer_id, make, model, variant, year, type, price, mileage,
                    fuel_type, transmission, color, main_image, description, status,
                    warranty_period, warranty_start_date, warranty_end_date, service_history
                FROM cars";

        if ($user['role'] === 'dealer' && !empty($user['dealer_id'])) {
            $sql .= " WHERE dealer_id = " . (int)$user['dealer_id'];
        }

        $sql .= " ORDER BY id DESC";

        $cars = $this->db->raw($sql)->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond([
            'status' => 'success',
            'cars' => $cars
        ]);
    }


    // UPDATE CAR — WITH WARRANTY RECALCULATION
    public function updateCars($id) {
        $user = $this->getCurrentUser();
        $input = $this->api->body();

        // Security: Dealer can only edit their own car
        if ($user['role'] === 'dealer') {
            $car = $this->db->raw("SELECT dealer_id FROM cars WHERE id = ?", [$id])->fetch();
            if (!$car || $car['dealer_id'] != $user['dealer_id']) {
                return $this->api->respond_error('Access denied: You can only edit your own cars', 403);
            }
        }

        // Recalculate warranty_end_date if needed
        $warranty_end_date = null;
        $warranty_period = $input['warranty_period'] ?? null;
        $warranty_start_date = $input['warranty_start_date'] ?? null;

            $warranty_end_date = null;
            if (!empty($input['warranty_period']) && !empty($input['warranty_start_date'])) {
                try {
                    $start = new DateTime($input['warranty_start_date']);
                    $end = (clone $start)->add(new DateInterval('P' . (int)$input['warranty_period'] . 'M'));
                    $warranty_end_date = $end->format('Y-m-d');
                } catch (Exception $e) {
                    // Ignore invalid dates
                }
            }

        $this->db->raw("UPDATE cars SET
            make = ?, model = ?, variant = ?, year = ?, type = ?, price = ?,
            mileage = ?, fuel_type = ?, transmission = ?, color = ?,
            main_image = ?, description = ?, status = ?,
            warranty_period = ?, warranty_start_date = ?, warranty_end_date = ?, service_history = ?
            WHERE id = ?", [
            $input['make'],
            $input['model'],
            $input['variant'] ?? null,
            $input['year'],
            $input['type'] ?? null,
            $input['price'],
            $input['mileage'] ?? null,
            $input['fuel_type'] ?? null,
            $input['transmission'] ?? null,
            $input['color'] ?? null,
            $input['main_image'] ?? null,
            $input['description'] ?? null,
            $input['status'] ?? 'available',
            $warranty_period,
            $warranty_start_date,
            $warranty_end_date,
            $input['service_history'] ?? null,
            $id
        ]);

        $this->api->respond([
            'status' => 'success',
            'message' => 'Car updated successfully!'
        ]);
    }

    public function listCarsPaginated() {
        // === Get Current User (safe) ===
        $user = $this->getCurrentUser() ?? ['role' => 'guest', 'dealer_id' => null];

        // === Pagination (safe defaults) ===
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = max(1, min(50, (int)($_GET['limit'] ?? 9)));
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        // === Global Search ===
        if (!empty($_GET['search'])) {
            $s = "%" . trim($_GET['search']) . "%";
            $where[] = "(make LIKE ? OR model LIKE ? OR variant LIKE ? OR description LIKE ? OR color LIKE ?)";
            $params = array_merge($params, [$s, $s, $s, $s, $s]);
        }

        // === Filters ===
        if (!empty($_GET['make'])) {
            $where[] = "make = ?";
            $params[] = $_GET['make'];
        }

        if (!empty($_GET['year'])) {
            $where[] = "year = ?";
            $params[] = (int)$_GET['year'];
        }

        // === Price Range ===
        if (isset($_GET['min_price']) && is_numeric($_GET['min_price']) && $_GET['min_price'] > 0) {
            $where[] = "price >= ?";
            $params[] = (int)$_GET['min_price'];
        }

        if (isset($_GET['max_price']) && is_numeric($_GET['max_price']) && $_GET['max_price'] > 0) {
            $where[] = "price <= ?";
            $params[] = (int)$_GET['max_price'];
        }

        // === Transmission ===
        if (!empty($_GET['transmission'])) {
            $where[] = "transmission = ?";
            $params[] = $_GET['transmission'];
        }

        // === Fuel Type (multiple support) ===
        if (!empty($_GET['fuel_type'])) {
            $fuelTypes = array_filter(explode(',', $_GET['fuel_type']));
            if (!empty($fuelTypes)) {
                $placeholders = str_repeat('?,', count($fuelTypes) - 1) . '?';
                $where[] = "fuel_type IN ($placeholders)";
                $params = array_merge($params, $fuelTypes);
            }
        }

        // === Dealer Restriction (only see own cars) ===
        if ($user['role'] === 'dealer' && !empty($user['dealer_id'])) {
            $where[] = "dealer_id = ?";
            $params[] = (int)$user['dealer_id'];
        }

        // === Build WHERE clause ===
        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // === Count Total Cars (for pagination) ===
        $countParams = $params;
        $totalQuery = "SELECT COUNT(*) FROM cars $whereSql";
        $total = (int)$this->db->raw($totalQuery, $countParams)->fetchColumn();

        // === Main Query — MAY WARRANTY & SERVICE NA! ===
        $mainParams = $params;
        $mainParams[] = $limit;
        $mainParams[] = $offset;

        $sql = "SELECT 
                    id, dealer_id, make, model, variant, year, type, price, mileage,
                    fuel_type, transmission, color, main_image, description, status,
                    warranty_period, warranty_start_date, warranty_end_date, service_history
                FROM cars 
                $whereSql 
                ORDER BY id DESC 
                LIMIT ? OFFSET ?";

        $cars = $this->db->raw($sql, $mainParams)->fetchAll(PDO::FETCH_ASSOC);

        // === Final Response ===
        $this->api->respond([
            'status' => 'success',
            'cars' => $cars,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => (int)ceil($total / $limit)
            ]
        ]);
    }

        // DELETE CAR
    public function deleteCars($id) {
        $user = $this->getCurrentUser();

        if ($user['role'] === 'dealer') {
            $car = $this->db->raw("SELECT dealer_id, main_image FROM cars WHERE id = ?", [$id])->fetch();
            if (!$car || $car['dealer_id'] != $user['dealer_id']) {
                return $this->api->respond_error('Access denied', 403);
            }

            // Optional: Delete image from storage
            if ($car['main_image'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $car['main_image'])) {
                unlink($_SERVER['DOCUMENT_ROOT'] . $car['main_image']);
            }
        }

        $this->db->table('cars')->where('id', $id)->delete();

        $this->api->respond([
            'status' => 'success',
            'message' => 'Car deleted successfully'
        ]);
    }

        // NEW FUNCTION — PARA LANG SA COMPARISON (SUPER FAST & CLEAN)
    public function compareCars()
    {
        // Read JSON body (LavaLust v4 style)
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $ids = $data['ids'] ?? [];

        // Validation: 1 or 2 cars only
        if (!is_array($ids) || count($ids) === 0 || count($ids) > 2) {
            return $this->api->respond([
                'status'  => 'error',
                'message' => 'Please select 1 or 2 cars to compare.'
            ], 400);
        }

        // Sanitize IDs
        $ids = array_map('intval', array_unique($ids));
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';

        try {
            // SELECT ALL NEEDED FIELDS — COMPATIBLE SA BAGONG DB STRUCTURE
            $sql = "
                SELECT 
                    id, make, model, variant, year, price, mileage,
                    transmission, fuel_type, color, main_image, description, status,
                    warranty_period, warranty_start_date, warranty_end_date, service_history
                FROM cars 
                WHERE id IN ($placeholders)
                ORDER BY FIELD(id, " . implode(',', $ids) . ")
            ";

            $stmt = $this->db->raw($sql, $ids);
            $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format warranty info — gagawin nating object para madaling gamitin sa frontend
            foreach ($cars as &$car) {
                $car['warranty'] = null;

                if ($car['warranty_end_date'] && $car['warranty_period']) {
                    $car['warranty'] = [
                        'period_months' => (int)$car['warranty_period'],
                        'start_date'    => $car['warranty_start_date'],
                        'end_date'      => $car['warranty_end_date'],
                        'remaining'     => $this->getMonthsRemaining($car['warranty_end_date'])
                    ];
                }

                // Clean up — tanggalin yung raw fields (hindi na kailangan sa frontend)
                unset(
                    $car['warranty_period'],
                    $car['warranty_start_date'],
                    $car['warranty_end_date']
                );
            }
            unset($car); // break reference

            $this->api->respond([
                'status' => 'success',
                'cars'   => $cars,
                'count'  => count($cars)
            ]);

        } catch (Exception $e) {
            $this->api->respond([
                'status'  => 'error',
                'message' => 'Failed to load cars for comparison.'
            ], 500);
        }
    }

    public function uploadCarImage()
    {
        $this->api->require_method('POST');

        // Check if file was uploaded
        if (!isset($_FILES['main_image_file']) || $_FILES['main_image_file']['error'] === UPLOAD_ERR_NO_FILE) {
            return $this->api->respond_error('No file uploaded', 400);
        }

        $file = $_FILES['main_image_file'];

        // Validate upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->api->respond_error('File upload error: ' . $file['error'], 400);
        }

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            return $this->api->respond_error('Only JPG, PNG, WebP allowed.', 400);
        }

        // Validate file size (max 3MB para safe)
        if ($file['size'] > 3 * 1024 * 1024) {
            return $this->api->respond_error('File too large. Max 3MB allowed.', 400);
        }

        // Convert to base64
        $imageData = file_get_contents($file['tmp_name']);
        $base64 = 'data:' . $file['type'] . ';base64,' . base64_encode($imageData);

        // Optional: limit size para hindi masyado malaki sa DB (3MB raw = ~4MB base64)
        if (strlen($base64) > 5 * 1024 * 1024) { // ~5MB encoded
            return $this->api->respond_error('Image too large after encoding.', 400);
        }

        // Return base64 string — i-save mo 'to sa `main_image` column ng cars table
        return $this->api->respond([
            'status' => 'success',
            'url'    => $base64,
            'path'   => $base64,
            'size'   => strlen($base64) . ' bytes (base64)',
            'tip'    => 'This is a base64 image — no file saved, works everywhere!'
        ]);
    }

//======================================================================================================================================

// ===================================================================
// DASHBOARD CHARTS: Dealer sees only his data
// ===================================================================
    public function cardistribution() {
        $user = $this->getCurrentUser();
        $sql = "SELECT make, model, variant, COUNT(*) AS stock_count 
                FROM cars WHERE status = 'available'";

        if ($user['role'] === 'dealer' && $user['dealer_id']) {
            $sql .= " AND dealer_id = " . (int)$user['dealer_id'];
        }
        $sql .= " GROUP BY make, model, variant ORDER BY make, model";

        $stocks = $this->db->raw($sql)->fetchAll(PDO::FETCH_ASSOC);
        $this->api->respond(['status' => 'success', 'stocks' => $stocks]);
    }

//======================================================================================================================================

// ===============================
//  Appointment Management
// ===============================
    public function createAppointment()
    {
        $this->api->require_method('POST');

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        file_put_contents('debug_input.log', $rawInput . PHP_EOL, FILE_APPEND);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->api->respond_error('Invalid JSON format', 400);
        }

        $required = ['car_id', 'appointment_at', 'full_name', 'email'];
        $missing = array_filter($required, fn($f) => empty(trim($input[$f] ?? '')));
        if (!empty($missing)) {
            return $this->api->respond_error('Missing required: ' . implode(', ', $missing), 400);
        }

        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->api->respond_error('Invalid email address', 400);
        }

        $appointmentAt = $input['appointment_at'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $appointmentAt)) {
            return $this->api->respond_error('Invalid appointment_at format. Use: YYYY-MM-DD HH:MM:SS', 400);
        }

        try {
            $dealerId = $input['dealer_id'] ?? 1;

            // INSERT WITHOUT user_id (safe even if column doesn't exist)
            $this->db->raw("
                INSERT INTO appointments 
                    (car_id, dealer_id, appointment_at, status, notes, 
                    full_name, email, phone, created_at) 
                VALUES 
                    (?, ?, ?, 'pending', ?, ?, ?, ?, NOW())
            ", [
                $input['car_id'],
                $dealerId,
                $appointmentAt,
                $input['notes'] ?? null,
                trim($input['full_name']),
                strtolower(trim($input['email'])),
                $input['phone'] ?? null
            ]);

            $appointmentId = $this->db->raw("SELECT LAST_INSERT_ID() as id")
                                    ->fetch(PDO::FETCH_ASSOC)['id'] ?? 0;

            if ($appointmentId <= 0) {
                return $this->api->respond_error('Failed to create appointment', 500);
            }

            // Get data for confirmation email
            $stmt = $this->db->raw("
                SELECT 
                    a.appointment_at, a.notes, a.full_name, a.email,
                    c.make, c.model, c.variant, c.year
                FROM appointments a
                JOIN cars c ON a.car_id = c.id
                WHERE a.id = ?
            ", [$appointmentId]);

            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data && filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->sendBookingConfirmationEmail(
                    [
                        'appointment_at' => $data['appointment_at'],
                        'notes'         => $data['notes'] ?? ''
                    ],
                    [
                        'make'     => $data['make'],
                        'model'    => $data['model'],
                        'variant'  => $data['variant'] ?? '',
                        'year'     => $data['year']
                    ],
                    [
                        'name'  => $data['full_name'],
                        'email' => $data['email']
                    ]
                );
            }

            return $this->api->respond([
                'message'        => 'Appointment booked successfully',
                'appointment_id' => $appointmentId
            ]);

        } catch (Exception $e) {
            error_log("Create appointment error: " . $e->getMessage());
            return $this->api->respond_error('Failed to book appointment', 500);
        }
    }

// ===================================================================
// LIST APPOINTMENTS (NO user_id JOIN — 100% Safe & Working)
// ===================================================================
    public function listAppointments()
    {
        $user = $this->getCurrentUser();

        $sql = "
            SELECT 
                a.id,
                a.dealer_id,
                a.full_name AS user_name,
                a.full_name,
                a.email,
                a.phone,
                c.make,
                c.model,
                c.variant,
                a.appointment_at,
                DATE_FORMAT(a.appointment_at, '%b %e, %Y at %l:%i %p') AS formatted_date,
                a.status,
                a.notes,
                a.created_at
            FROM appointments a
            JOIN cars c ON a.car_id = c.id
        ";

        $params = [];
        if ($user['role'] === 'dealer' && !empty($user['dealer_id'])) {
            $sql .= " WHERE a.dealer_id = ?";
            $params[] = $user['dealer_id'];
        }

        $sql .= " ORDER BY a.appointment_at DESC";

        try {
            $appointments = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

            return $this->api->respond([
                'status'       => 'success',
                'appointments' => $appointments
            ]);
        } catch (Exception $e) {
            error_log("List appointments error: " . $e->getMessage());
            return $this->api->respond_error('Failed to load appointments', 500);
        }
    }

// ===================================================================
// UPDATE APPOINTMENT (Status, Notes, Date/Time — Fully Safe)
// ===================================================================
    public function updateAppointment($id)
    {
        $this->api->require_method('PUT');
        $input = $this->api->body();

        $validStatuses = ['pending', 'approved', 'completed', 'cancelled', 'rejected'];
        if (empty($input['status']) || !in_array($input['status'], $validStatuses)) {
            return $this->api->respond_error('Invalid or missing status', 400);
        }

        try {
            // Get current appointment (no user join needed)
            $stmt = $this->db->raw("
                SELECT a.*, c.make, c.model, c.variant, c.year,
                    a.full_name AS client_name,
                    a.email AS client_email
                FROM appointments a
                JOIN cars c ON a.car_id = c.id
                WHERE a.id = ?
            ", [$id]);

            $appt = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$appt) {
                return $this->api->respond_error('Appointment not found', 404);
            }

            $oldStatus = $appt['status'];
            $newStatus = $input['status'];

            // Build dynamic update
            $updates = ['status = ?'];
            $params  = [$newStatus];

            if (isset($input['notes']) && $input['notes'] !== null) {
                $updates[] = 'notes = ?';
                $params[]  = $input['notes'] === '' ? null : trim($input['notes']);
            }

            if (!empty($input['appointment_at'])) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $input['appointment_at'])) {
                    return $this->api->respond_error('Invalid datetime format', 400);
                }
                $updates[] = 'appointment_at = ?';
                $params[]  = $input['appointment_at'];
            }

            $params[] = $id; // for WHERE id = ?

            $this->db->raw(
                "UPDATE appointments SET " . implode(', ', $updates) . " WHERE id = ?",
                $params
            );

            // Send email on important status change
            if ($oldStatus !== $newStatus && in_array($newStatus, ['approved', 'completed', 'cancelled', 'rejected'])) {
                $this->sendAppointmentStatusEmail(
                    [
                        'appointment_at' => $appt['appointment_at'],
                        'notes'         => $appt['notes'] ?? ''
                    ],
                    [
                        'make'    => $appt['make'],
                        'model'   => $appt['model'],
                        'variant' => $appt['variant'] ?? '',
                        'year'    => $appt['year']
                    ],
                    [
                        'name'  => $appt['client_name'],
                        'email' => $appt['client_email']
                    ],
                    $newStatus
                );
            }

            return $this->api->respond([
                'message' => 'Appointment updated successfully'
            ]);

        } catch (Exception $e) {
            error_log("Update appointment error: " . $e->getMessage());
            return $this->api->respond_error('Update failed', 500);
        }
    }

    private function sendAppointmentStatusEmail($appointmentData, $carInfo, $userInfo, $newStatus)
    {
        $resend = \Resend::client('re_7hRjc2KA_P8stiWxVFw6wdvkMcyrFfe9S'); // o yung bagong key mo

        $dateFormatted = date('F j, Y \a\t g:i A', strtotime($appointmentData['appointment_at']));
        $carName = trim("{$carInfo['make']} {$carInfo['model']} {$carInfo['variant']} ({$carInfo['year']})");

        $status = strtolower($newStatus);
        $config = [
            'approved' => [
                'title'   => 'Appointment APPROVED!',
                'message' => 'Your appointment has been <strong style="color:#27ae60;font-size:20px">APPROVED</strong>!',
                'color'   => '#27ae60',
                'gradient' => 'linear-gradient(135deg, #27ae60, #1e8449)',
                'subject' => "Appointment APPROVED! {$carInfo['make']} {$carInfo['model']}"
            ],
            'completed' => [
                'title'   => 'Thank You for Visiting!',
                'message' => 'Your appointment has been marked as <strong style="color:#3498db">COMPLETED</strong>.',
                'color'   => '#3498db',
                'gradient' => 'linear-gradient(135deg, #3498db, #2980b9)',
                'subject' => 'Thank You! Appointment Completed'
            ],
            'cancelled' => [
                'title'   => 'Appointment Cancelled',
                'message' => 'We’re sorry, but your appointment has been <strong style="color:#e74c3c">CANCELLED</strong>.',
                'color'   => '#e74c3c',
                'gradient' => 'linear-gradient(135deg, #e74c3c, #c0392b)',
                'subject' => 'Appointment Cancelled'
            ],
            'rejected' => [
                'title'   => 'Appointment Rejected',
                'message' => 'We’re sorry, but your appointment has been <strong style="color:#e74c3c">REJECTED</strong>.',
                'color'   => '#e74c3c',
                'gradient' => 'linear-gradient(135deg, #e74c3c, #c0392b)',
                'subject' => 'Appointment Rejected'
            ]
        ];

        // Kung hindi kasama sa list (ex: pending), wag mag-send
        if (!isset($config[$status])) return;

        $c = $config[$status];

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1);'>
            <div style='background: {$c['gradient']}; padding: 40px 20px; text-align: center; color: white;'>
                <h1 style='margin:0; font-size: 32px;'>{$c['title']}</h1>
            </div>
            <div style='padding: 40px 30px; text-align: center;'>
                <p style='font-size: 18px;'>Hi <strong>{$userInfo['name']}</strong>,</p>
                <p style='font-size: 17px; line-height: 1.6;'>{$c['message']}</p>
                
                <div style='background: #f8f9fa; padding: 30px; border-radius: 12px; margin: 35px 0; border-left: 7px solid {$c['color']};'>
                    <h2 style='margin-top:0; color: #2c3e50;'>Appointment Details</h2>
                    <p style='margin:8px 0; font-size:16px;'><strong>Car:</strong> {$carName}</p>
                    <p style='margin:8px 0; font-size:16px;'><strong>Date & Time:</strong> {$dateFormatted}</p>"
                    . (!empty($appointmentData['notes']) ? "<p style='margin:15px 0 0;'><strong>Your Notes:</strong><br><em>{$appointmentData['notes']}</em></p>" : "")
                . "</div>";

        // Optional button for completed
        if ($status === 'completed') {
            $html .= "<p>We'd love to see you again!</p>
                    <a href='https://ride-zones-front-end-liard.vercel.app/cars' style='background:{$c['color']};color:white;padding:16px 36px;text-decoration:none;border-radius:10px;font-weight:bold;font-size:16px;'>
                        Browse More Luxury Cars
                    </a>";
        }

        // Optional button for cancelled/rejected
        if (in_array($status, ['cancelled', 'rejected'])) {
            $html .= "<p>You can book another slot anytime.</p>
                    <a href='https://ride-zones-front-end-liard.vercel.app/cars' style='background:{$c['color']};color:white;padding:16px 36px;text-decoration:none;border-radius:10px;font-weight:bold;font-size:16px;'>
                        Book Another Appointment
                    </a>";
        }

        $html .= "
                <div style='margin-top:50px; padding-top:20px; border-top:2px dashed #ddd; color:#777; font-size:14px;'>
                    Thank you for choosing RIDEZONE — Luxury Redefined.
                </div>
            </div>
            <div style='background: #1a1a1a; color: #aaa; padding: 20px; text-align: center; font-size: 13px;'>
                © " . date('Y') . " RIDEZONE • Philippines' Premier Luxury Car Platform
            </div>
        </div>";

        try {
            $resend->emails->send([
                'from'    => 'RIDEZONE <noreply@resend.dev>',
                'to'      => $userInfo['email'],
                'subject' => $c['subject'],
                'html'    => $html
            ]);
            error_log("Appointment {$newStatus} email sent via Resend to: {$userInfo['email']}");
        } catch (\Exception $e) {
            error_log("Resend appointment {$newStatus} email failed: " . $e->getMessage());
        }
    }

// 1. PARA SA BOOKING LANG (Pending)
    private function sendBookingConfirmationEmail($appointmentData, $carInfo, $userInfo)
    {
        $resend = \Resend::client('re_7hRjc2KA_P8stiWxVFw6wdvkMcyrFfe9S');

        $dateFormatted = date('F j, Y \a\t g:i A', strtotime($appointmentData['appointment_at']));
        $carName = "{$carInfo['make']} {$carInfo['model']} {$carInfo['variant']} ({$carInfo['year']})";

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1);'>
            <div style='background: linear-gradient(135deg, #f59e0b, #e67e22); padding: 40px 20px; text-align: center; color: white;'>
                <h1>Appointment Request Received!</h1>
            </div>
            <div style='padding: 40px 30px; text-align: center;'>
                <p style='font-size: 18px;'>Hi <strong>{$userInfo['name']}</strong>,</p>
                <p>Salamat sa pag-book! Your appointment request has been received and is <strong style='color: #e67e22;'>PENDING APPROVAL</strong>.</p>
                
                <div style='background: #f8f9fa; padding: 25px; border-radius: 12px; margin: 30px 0; border-left: 6px solid #f59e0b;'>
                    <h2 style='margin-top: 0;'>Appointment Details</h2>
                    <p><strong>Car:</strong> {$carName}</p>
                    <p><strong>Date & Time:</strong> {$dateFormatted}</p>
                    " . (!empty($appointmentData['notes']) ? "<p><strong>Notes:</strong><br><em>{$appointmentData['notes']}</em></p>" : "") . "
                </div>

                <p>We will notify you once it's approved!</p>
            </div>
            <div style='background: #1a1a1a; color: #aaa; padding: 20px; text-align: center; font-size: 13px;'>
                © " . date('Y') . " LavaLust Cars • Luxury Redefined
            </div>
        </div>";

        try {
            $resend->emails->send([
                'from'    => 'LavaLust Cars <noreply@resend.dev>',
                'to'      => $userInfo['email'],
                'subject' => "Appointment Request Received – {$carInfo['make']} {$carInfo['model']}",
                'html'    => $html
            ]);
            error_log("Booking confirmation sent via Resend to: {$userInfo['email']}");
        } catch (\Exception $e) {
            error_log("Resend booking email failed: " . $e->getMessage());
        }
    }

    public function dataappointments() {
            $user = $this->getCurrentUser();
            $year  = $_GET['year']  ?? date('Y');
            $month = $_GET['month'] ?? date('m');

            $sql = "SELECT c.make, c.model, c.variant, COUNT(a.id) AS total_appointments
                    FROM cars c
                    LEFT JOIN appointments a ON a.car_id = c.id 
                        AND YEAR(a.appointment_at) = ? AND MONTH(a.appointment_at) = ?";

            $params = [$year, $month];
            if ($user['role'] === 'dealer' && $user['dealer_id']) {
                $sql .= " AND a.dealer_id = ?";
                $params[] = $user['dealer_id'];
            }
            $sql .= " GROUP BY c.make, c.model, c.variant ORDER BY c.make, c.model";

            $data = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
            $this->api->respond(['status' => 'success', 'data' => $data]);
    }


    public function downloadFile($filename)
    {
        $filePath = dirname(__DIR__, 2) . '/public/uploads/' . basename($filename);

        if (!file_exists($filePath)) {
            return $this->response->json([
                'status' => 'error',
                'message' => 'File not found.'
            ], 404);
        }
        exit;

    }

//==========================================================================================================================================

// ==============================
// DEALER MANAGEMENT (CRUD) - Based on Your Exact Table
// ==============================
    public function listDealers()
    {
        try {
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = max(1, min(50, (int)($_GET['limit'] ?? 10)));
            $offset = ($page - 1) * $limit;
            $search = trim($_GET['search'] ?? '');

            $where = [];
            $params = [];

            if ($search !== '') {
                $like = "%$search%";
                $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ? OR address LIKE ? OR description LIKE ?)";
                $params = array_fill(0, 5, $like); // 5 fields
            }

            $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $sql = "SELECT id, name, description, address, phone, email, created_at 
                    FROM dealers 
                    $whereSql 
                    ORDER BY created_at DESC 
                    LIMIT ? OFFSET ?";

            $queryParams = array_merge($params, [$limit, $offset]);
            $stmt = $this->db->raw($sql, $queryParams);
            $dealers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Total count for pagination
            $countSql = "SELECT COUNT(*) FROM dealers $whereSql";
            $total = (int)$this->db->raw($countSql, $params)->fetchColumn();

            $this->api->respond([
                'status' => 'success',
                'dealers' => $dealers,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total_records' => $total,
                    'total_pages' => $total > 0 ? ceil($total / $limit) : 1
                ]
            ]);

        } catch (Exception $e) {
            $this->api->respond_error('Failed to fetch dealers: ' . $e->getMessage(), 500);
        }
    }

    public function createDealer()
    {
        $this->api->require_method('POST');
        //$this->requireAdmin(); // uses helper below

        $input = $this->api->body();

        $required = ['name'];
        $missing = array_reduce($required, fn($carry, $field) => 
            empty($input[$field]) ? [...$carry, $field] : $carry, []);

        if (!empty($missing)) {
            return $this->api->respond_error('Missing required fields: ' . implode(', ', $missing), 400);
        }

        // Optional: Prevent duplicate email
        if (!empty($input['email'])) {
            $exists = $this->db->raw("SELECT id FROM dealers WHERE email = ?", [$input['email']])->fetch();
            if ($exists) {
                return $this->api->respond_error('A dealer with this email already exists', 400);
            }
        }

        try {
            $this->db->raw("
                INSERT INTO dealers 
                    (name, description, address, phone, email)
                VALUES 
                    (?, ?, ?, ?, ?, ?)
            ", [
                $input['name'],
                $input['description'] ?? null,
                $input['address'] ?? null,
                $input['phone'] ?? null,
                $input['email'] ?? null
            ]);

            $dealerId = $this->db->raw("SELECT LAST_INSERT_ID()")->fetchColumn();

            $this->api->respond([
                'status' => 'success',
                'message' => 'Dealer created successfully',
                'dealer_id' => (int)$dealerId
            ]);
        } catch (Exception $e) {
            $this->api->respond_error('Failed to create dealer: ' . $e->getMessage(), 500);
        }
    }

    public function updateDealer($id)
    {
        $this->api->require_method('PUT');
        //$this->requireAdmin();

        $input = $this->api->body();

        // Check if dealer exists
        $exists = $this->db->raw("SELECT id FROM dealers WHERE id = ?", [$id])->fetch();
        if (!$exists) {
            return $this->api->respond_error('Dealer not found', 404);
        }

        $allowed = ['name', 'description', 'address', 'phone', 'email'];
        $set = [];
        $params = [];

        foreach ($allowed as $field) {
            if (isset($input[$field])) {
                $set[] = "$field = ?";
                $params[] = $input[$field] === '' ? null : $input[$field];
            }
        }

        if (empty($set)) {
            return $this->api->respond_error('No data provided to update', 400);
        }

        $params[] = $id;
        $setSql = implode(', ', $set);

        try {
            $this->db->raw("UPDATE dealers SET $setSql WHERE id = ?", $params);
            $this->api->respond([
                'status' => 'success',
                'message' => 'Dealer updated successfully'
            ]);
        } catch (Exception $e) {
            $this->api->respond_error('Update failed: ' . $e->getMessage(), 500);
        }
    }


    public function deleteDealer($id)
    {
        $this->api->require_method('DELETE');
        //$this->requireAdmin();

        $dealer = $this->db->raw("SELECT id FROM dealers WHERE id = ?", [$id])->fetch();
        if (!$dealer) {
            return $this->api->respond_error('Dealer not found', 404);
        }

        // Optional: Block delete if dealer has cars
        $hasCars = $this->db->raw("SELECT 1 FROM cars WHERE dealer_id = ? LIMIT 1", [$id])->fetch();
        if ($hasCars) {
            return $this->api->respond_error('Cannot delete dealer that has listed cars', 400);
        }

        try {
            $this->db->raw("DELETE FROM dealers WHERE id = ?", [$id]);
            $this->api->respond([
                'status' => 'success',
                'message' => 'Dealer deleted successfully'
            ]);
        } catch (Exception $e) {
            $this->api->respond_error('Delete failed: ' . $e->getMessage(), 500);
        }
    }

//=====================================================================================================================================

// ===================================================================
// FORGOT PASSWORD — 100% COMPATIBLE SA IYONG CUSTOM API SYSTEM
// ===================================================================
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
        $this->sendPasswordResetEmail($user['name'], $email, $token);

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

// ===================================================================
// SEND PASSWORD RESET EMAIL (LUXURY DESIGN)
// ===================================================================
    private function sendPasswordResetEmail($name, $email, $token)
    {
        
        // Ilagay mo dito ang Resend API key mo (mas safe kung sa .env)
        $resend = \Resend::client('re_7hRjc2KA_P8stiWxVFw6wdvkMcyrFfe9S');
        
        $resetLink = "https://ride-zones-front-end-liard.vercel.app/reset-password?token={$token}&email=" . urlencode($email);

        try {
            $resend->emails->send([
                'from'    => 'RIDEZONE <noreply@resend.dev>', // Dapat verified domain or use resend.dev for testing
                'to'      => [$email],
                'subject' => 'RIDEZONE • Reset Your Password',
                'html'    => "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; background: #0a0a0a; color: white; border-radius: 20px; overflow: hidden; border: 1px solid #333;'>
                    <div style='background: linear-gradient(135deg, #ef4444, #b91c1c); padding: 50px 30px; text-align: center;'>
                        <h1 style='margin:0; font-size: 36px; font-weight: bold;'>RIDEZONE</h1>
                        <p style='margin: 10px 0 0; opacity: 0.9;'>Luxury Redefined</p>
                    </div>
                    <div style='padding: 50px 40px; text-align: center;'>
                        <div style='width: 80px; height: 80px; background: #ef4444; border-radius: 50%; margin: 0 auto 30px; display: flex; align-items: center; justify-content: center;'>
                            <span style='font-size: 40px;'>Key</span>
                        </div>
                        <h2 style='font-size: 28px; margin-bottom: 20px;'>Password Reset Request</h2>
                        <p style='font-size: 18px; line-height: 1.6; color: #ccc;'>
                            Hi <strong>{$name}</strong>,<br><br>
                            We received a request to reset your RIDEZONE password.
                        </p>
                        
                        <div style='margin: 50px 0;'>
                            <a href='{$resetLink}' style='background: linear-gradient(135deg, #ef4444, #b91c1c); color: white; padding: 18px 50px; border-radius: 15px; text-decoration: none; font-weight: bold; font-size: 18px; display: inline-block; box-shadow: 0 10px 30px rgba(239,68,68,0.4);'>
                                Reset Password Now
                            </a>
                        </div>

                        <p style='color: #888; font-size: 14px; line-height: 1.6;'>
                            This link will expire in <strong>60 minutes</strong>.<br>
                            If you didn't request this, please ignore this email.
                        </p>
                    </div>
                    <div style='background: #111; padding: 30px; text-align: center; font-size: 13px; color: #666;'>
                        © " . date('Y') . " RIDEZONE • Philippines' Premier Luxury Car Platform
                    </div>
                </div>",
            ]);

            error_log("Password reset email sent via Resend to: {$email}");
        } catch (\Exception $e) {
            error_log("Resend email failed: " . $e->getMessage());
            // Optional: throw $e; kung gusto mo i-propagate ang error
        }
    }

    // ===================================================================
    // HELPER: Kunin ang current user mula sa localStorage via X-User header
    // ===================================================================
    private function getCurrentUser() {
        $headers = getallheaders();
        $userHeader = $headers['X-User'] ?? $headers['x-user'] ?? '';

        if ($userHeader) {
            $userData = json_decode($userHeader, true);
            if (is_array($userData)) {
                return [
                    'id'        => $userData['id'] ?? null,
                    'role'      => $userData['role'] ?? 'buyer',
                    'dealer_id' => $userData['dealer_id'] ?? null,
                    'name'      => $userData['name'] ?? 'User'
                ];
            }
        }
        // Fallback: admin (safe for testing)
        return ['id' => null, 'role' => 'admin', 'dealer_id' => null];
    }

    public function googleCallback()
{
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['credential'] ?? null;

    if (!$token) {
        echo json_encode(['success' => false, 'error' => 'No token provided']);
        exit;
    }

    try {
        $client = new Google\Client();
        // PALITAN MO ‘TO NG BAGONG CLIENT ID MO!!!
        $client->setClientId('1090968034876-fh3nbirtjc4sgef6itbbn50pggo1j3l0.apps.googleusercontent.com');

        $payload = $client->verifyIdToken($token);

        if (!$payload) {
            echo json_encode(['success' => false, 'error' => 'Invalid Google token']);
            exit;
        }

        $googleId = $payload['sub'];
        $email    = $payload['email'];
        $name     = $payload['name'] ?? 'User';
        $picture  = $payload['picture'] ?? null;

        // Check existing user
        $query = $this->db->table('users')
            ->where('google_id', $googleId)
            ->or_where('email', $email)
            ->get();

        $user = $query->row_array(); // mas clean

        if (!$user) {
            // Create new user
            $uniqueName = $this->generateUniqueUsername($name, $email);

            $this->db->table('users')->insert([
                'name'               => $uniqueName,
                'email'              => $email,
                'google_id'          => $googleId,
                'avatar'             => $picture,
                'role'               => 'dealer',  // or 'buyer' kung gusto mo
                'email_verified_at'  => date('Y-m-d H:i:s'),
                'created_at'         => date('Y-m-d H:i:s')
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
            // Link Google ID if not yet linked
            if (empty($user['google_id'])) {
                $this->db->table('users')
                    ->where('id', $user['id'])
                    ->update(['google_id' => $googleId]);
            }
        }

        $this->setUserSession($user);

        echo json_encode([
            'success' => true,
            'user' => [
                'id'     => $user['id'],
                'name'   => $user['name'],
                'email'  => $user['email'],
                'role'   => $user['role'],
                'avatar' => $user['avatar']
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
}

//=====================================================================================================================================
//=====================================================================================================================================