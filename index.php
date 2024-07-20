<?php

header('Content-Type: application/json');

// Database connection setup
$dsn = 'mysql:host=localhost;dbname=database';
$username = 'root';
$password = '';

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}

// Function to run a query with parameters
function run_query(string $query, array $values)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare($query);

        foreach ($values as $param => $param_value) {
            $stmt->bindValue($param, $param_value);
        }

        $stmt->execute();

        // Return last insert ID for INSERT queries
        if (strpos(strtolower($query), 'insert') !== false) {
            return $pdo->lastInsertId();
        }

        // Fetching results for SELECT queries
        if (strpos(strtolower($query), 'select') !== false) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return true;
    } catch (PDOException $e) {
        die('Query failed: ' . $e->getMessage());
    }
}

// Function to get user info by national code
function get_user_info(string $national_id)
{
    $query = 'SELECT * FROM users WHERE national_id = :national_id';
    $values = [':national_id' => $national_id];

    return run_query($query, $values);
}

// Function to get license info by license ID
function get_license_info(string $license_id)
{
    $query = 'SELECT * FROM licenses WHERE license_id = :license_id';
    $values = [':license_id' => $license_id];

    return run_query($query, $values);
}

// Function to create a license request
function create_license_request(string $national_id, string $license_id)
{
    $query = 'INSERT INTO licenserequests (approval_status, national_id, license, ExpireTime) VALUES (:approval_status, :national_id, :license, :ExpireTime)';

    $values = [
        ':approval_status' => 'pending',
        ':national_id' => $national_id,
        ':license' => $license_id,
        ':ExpireTime' => null // Set to NULL initially
    ];

    return run_query($query, $values);
}

// Function to update the approval status of a license request
function update_license_request_status(int $request_id, string $status)
{
    global $pdo;

    // Check if the status is '0' or '1'
    if ($status == '0') {
        $query = 'UPDATE licenserequests SET approval_status = :status, ExpireTime = NULL WHERE id = :request_id';
    } elseif ($status === '1') {
        // Fetch license and validity duration
        $stmt = $pdo->prepare("SELECT license FROM licenserequests WHERE id = :request_id");
        $stmt->execute([':request_id' => $request_id]);
        $license = $stmt->fetchColumn();

        if ($license === false) {
            throw new Exception("License request not found for ID: $request_id");
        }

        $stmt = $pdo->prepare("SELECT validaty, validaty_unit FROM licenses WHERE license_id = :license_id");
        $stmt->execute([':license_id' => $license]);
        $licenseInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($licenseInfo === false) {
            throw new Exception("License not found for license ID: $license");
        }

        $validaty = $licenseInfo['validaty'];
        $validaty_unit = $licenseInfo['validaty_unit'];

        $expireTime = new DateTime();

        // Use switch to adjust the expire time based on validaty_unit
        switch ($validaty_unit) {
            case 1:
                // Validaty unit is in days
                $expireTime->modify('+' . $validaty . ' days');
                break;
            case 2:
                // Validaty unit is in months
                $expireTime->modify('+' . $validaty . ' months');
                break;
            case 3:
                // Validaty unit is in years
                $expireTime->modify('+' . $validaty . ' years');
                break;
            default:
                throw new Exception("Invalid validaty unit: $validaty_unit");
        }

        $expireTimeFormatted = $expireTime->format('Y-m-d H:i:s');

        $query = 'UPDATE licenserequests SET approval_status = :status, ExpireTime = :expireTime WHERE id = :request_id';
    } else {
        throw new Exception("Invalid status value: $status");
    }

    // Prepare and execute the statement
    $stmt = $pdo->prepare($query);
    return $stmt->execute([
        ':status' => $status,
        ':request_id' => $request_id,
        ':expireTime' => $expireTimeFormatted ?? null  // Ensure to pass NULL if the expireTime is not set
    ]);
}



// Function handler to coordinate the creation of license requests
function handler(string $national_id, string $license_id)
{
    // Validate input
    if (empty($national_id) || empty($license_id)) {
        throw new InvalidArgumentException('National ID and License ID must be provided.');
    }

    // Get user and license info
    $user_info = get_user_info($national_id);
    $license_info = get_license_info($license_id);

    // Create license request
    $create_license_request = create_license_request($national_id, $license_id);

    return [
        'user' => $user_info ? $user_info[0] : null, // Assuming there's only one user per national_id
        'license' => $license_info ? $license_info[0] : null, // Assuming there's only one license per license_id
        'license_request' => $create_license_request
    ];
}

// Function handler to update the license request status
function update_handler(int $request_id, string $status)
{
    // Validate input
    if (empty($request_id) || empty($status)) {
        throw new InvalidArgumentException('Request ID and status must be provided.');
    }

    // Update license request status
    $update_result = update_license_request_status($request_id, $status);

    return [
        'request_id' => $request_id,
        'status' => $status,
        'update_result' => $update_result
    ];
}

// Check if POST data exists
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    // Retrieve and sanitize POST data
    $input = json_decode(file_get_contents('php://input'), true);
    $national_id = isset($input["national_id"]) ? trim($input["national_id"]) : null;
    $license_id = isset($input["license_id"]) ? trim($input["license_id"]) : null;

    try {
        if ($national_id === null || $license_id === null) {
            throw new InvalidArgumentException('National ID and License ID must be provided.');
        }
        $result = handler($national_id, $license_id);
        echo json_encode($result);
    } catch (InvalidArgumentException $e) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => $e->getMessage()]);
    }
} elseif ($method === 'PUT' || $method === 'PATCH') {
    // Retrieve and sanitize PUT/PATCH data
    $input = json_decode(file_get_contents('php://input'), true);
    $request_id = isset($input["request_id"]) ? (int) $input["request_id"] : null;
    $status = isset($input["status"]) ? trim($input["status"]) : null;

    try {
        if ($request_id === null || $status === null) {
            throw new InvalidArgumentException('Request ID and status must be provided.');
        }
        $result = update_handler($request_id, $status);
        echo json_encode($result);
    } catch (InvalidArgumentException $e) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method not allowed']);
}
