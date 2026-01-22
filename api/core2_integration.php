<?php

/**
 * Core 2 Integration Class
 * For use by Core 1 (Shipment System) to send data to Core 2 (Route/Booking System)
 * Uses HTTP API calls to booking_api.php
 */

// API Configuration
// Make sure this matches the Core 2 IP and Folder Path exactly
define('CORE2_API_BASE_URL', 'http://192.168.1.15/NEWCORE2026/api/booking_api.php');
define('CORE2_API_KEY', 'Log1'); // Ensure this matches Core 2's expected key

class Core2Integration
{

    /**
     * Cache for GET requests to avoid multiple calls
     */
    private static $cache = [];

    /**
     * Make HTTP API call with Authentication and Payload support
     * @param string $endpoint The endpoint (e.g., '/bookings')
     * @param string $method GET, POST, PUT, DELETE
     * @param array $payload Data to send (for POST/PUT)
     * @return array API response data
     */
    private static function makeAPICall($endpoint, $method = 'GET', $payload = [])
    {
        $url = CORE2_API_BASE_URL . $endpoint;

        // Prepare Payload
        $jsonData = !empty($payload) ? json_encode($payload) : null;

        // Define Authentication Methods
        $methods = [
            // Method 1: Header + URL Param
            [
                'url' => $url . (strpos($url, '?') === false ? '?' : '&') . 'api_key=' . urlencode(CORE2_API_KEY),
                'headers' => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'X-API-Key: ' . CORE2_API_KEY,
                    'User-Agent: CORE1/IntegrationClient'
                ]
            ],
            // Method 2: Authorization Header
            [
                'url' => $url,
                'headers' => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . CORE2_API_KEY,
                    'User-Agent: CORE1/IntegrationClient'
                ]
            ]
        ];

        // Loop through auth methods until one works
        foreach ($methods as $authMethod) {
            $ch = curl_init();

            // Basic Options
            curl_setopt_array($ch, [
                CURLOPT_URL => $authMethod['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => $authMethod['headers'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);

            // Handle HTTP Verbs
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($jsonData) curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            } elseif ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($jsonData) curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }

            $response = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Valid Responses (200 OK, 201 Created)
            if ($response !== false && ($httpCode >= 200 && $httpCode < 300)) {
                $data = json_decode($response, true);
                // Return data if valid JSON, or error if parse failed
                if (json_last_error() === JSON_ERROR_NONE) {
                    return is_array($data) ? $data : ['success' => true, 'data' => $data];
                }
            }

            // If it was a 400+ error from the API specifically, return it immediately (don't retry auth)
            if ($httpCode >= 400 && $response) {
                $data = json_decode($response, true);
                return $data ?: ['success' => false, 'error' => "HTTP $httpCode", 'message' => $response];
            }
        }

        // If all methods failed
        return [
            'success' => false,
            'error' => 'Connection Failed',
            'message' => 'Unable to connect to Core 2. HTTP: ' . ($httpCode ?? 'N/A') . ' | Error: ' . ($curlErr ?? 'None')
        ];
    }

    /**
     * Create a new Booking in Core 2
     * @param array $bookingData Array of booking details
     * @return array API Response
     */
    public static function createBooking($bookingData)
    {
        try {
            return self::makeAPICall('/bookings', 'POST', $bookingData);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get All Bookings from Core 2
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getAllBookings($limit = 50, $offset = 0)
    {
        try {
            $endpoint = "/bookings?limit=$limit&offset=$offset";

            // Check Cache
            if (isset(self::$cache[$endpoint])) return self::$cache[$endpoint];

            $result = self::makeAPICall($endpoint, 'GET');

            if (isset($result['bookings'])) {
                self::$cache[$endpoint] = ['success' => true, 'data' => $result['bookings'], 'total' => $result['total'] ?? 0];
                return self::$cache[$endpoint];
            }

            return ['success' => false, 'error' => 'Invalid response format', 'data' => []];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get Specific Booking by ID
     * @param int $id
     * @return array
     */
    public static function getBookingById($id)
    {
        return self::makeAPICall("/booking/$id", 'GET');
    }

    /**
     * Search Bookings
     * @param string $query
     * @return array
     */
    public static function searchBookings($query)
    {
        $endpoint = "/bookings?search=" . urlencode($query);
        $result = self::makeAPICall($endpoint, 'GET');

        if (isset($result['bookings'])) {
            return ['success' => true, 'data' => $result['bookings'], 'total' => count($result['bookings'])];
        }
        return ['success' => false, 'error' => 'No bookings found or error'];
    }

    /**
     * Update a Booking
     * @param int $id
     * @param array $data Fields to update
     * @return array
     */
    public static function updateBooking($id, $data)
    {
        return self::makeAPICall("/booking/$id", 'PUT', $data);
    }

    /**
     * Delete a Booking
     * @param int $id
     * @return array
     */
    public static function deleteBooking($id)
    {
        return self::makeAPICall("/booking/$id", 'DELETE');
    }
}
?>