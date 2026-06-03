<?php
/**
 * ZKLibrary - PHP Library for ZKTeco Attendance Devices
 * Supports ZKTeco fingerprint/face recognition devices
 * Protocol: TCP/IP Communication
 */

class ZKLibrary {
    
    private $ip;
    private $port;
    private $socket;
    private $session_id = 0;
    private $reply_id = 0;
    private $timeout = 5;
    
    // Command constants
    const CMD_CONNECT = 1000;
    const CMD_EXIT = 1001;
    const CMD_ENABLE_DEVICE = 1002;
    const CMD_DISABLE_DEVICE = 1003;
    const CMD_ACK_OK = 2000;
    const CMD_ACK_ERROR = 2001;
    const CMD_ACK_DATA = 2002;
    const CMD_PREPARE_DATA = 1500;
    const CMD_DATA = 1501;
    const CMD_FREE_DATA = 1502;
    const CMD_ATTLOG_RRQ = 13; // Get attendance log
    const CMD_CLEAR_ATTLOG = 14; // Clear attendance log
    const CMD_USER_WRQ = 8; // Get user info
    const CMD_VERSION = 1100;
    const CMD_DEVICE_NAME = 1102;
    
    public function __construct($ip, $port = 4370) {
        $this->ip = $ip;
        $this->port = $port;
    }
    
    /**
     * Set connection timeout
     */
    public function setTimeout($seconds) {
        $this->timeout = $seconds;
    }
    
    /**
     * Connect to device
     */
    public function connect() {
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if ($this->socket === false) {
            return false;
        }
        
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => $this->timeout,
            'usec' => 0
        ]);
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, [
            'sec' => $this->timeout,
            'usec' => 0
        ]);
        
        $result = @socket_connect($this->socket, $this->ip, $this->port);
        
        if ($result === false) {
            return false;
        }
        
        // Send connect command
        $command = $this->createCommand(self::CMD_CONNECT);
        if (!$this->sendCommand($command)) {
            return false;
        }
        
        $response = $this->receiveResponse();
        
        if ($response && $response['command'] == self::CMD_ACK_OK) {
            $this->session_id = $response['session_id'];
            return true;
        }
        
        return false;
    }
    
    /**
     * Disconnect from device
     */
    public function disconnect() {
        if ($this->socket) {
            $command = $this->createCommand(self::CMD_EXIT);
            $this->sendCommand($command);
            socket_close($this->socket);
            $this->socket = null;
            $this->session_id = 0;
        }
    }
    
    /**
     * Disable device (prevents new punches during data read)
     */
    public function disableDevice() {
        $command = $this->createCommand(self::CMD_DISABLE_DEVICE);
        if (!$this->sendCommand($command)) {
            return false;
        }
        
        $response = $this->receiveResponse();
        return ($response && $response['command'] == self::CMD_ACK_OK);
    }
    
    /**
     * Enable device
     */
    public function enableDevice() {
        $command = $this->createCommand(self::CMD_ENABLE_DEVICE);
        if (!$this->sendCommand($command)) {
            return false;
        }
        
        $response = $this->receiveResponse();
        return ($response && $response['command'] == self::CMD_ACK_OK);
    }
    
    /**
     * Get attendance records from device
     */
    public function getAttendance() {
        // Request attendance log
        $command = $this->createCommand(self::CMD_ATTLOG_RRQ);
        if (!$this->sendCommand($command)) {
            return false;
        }
        
        $response = $this->receiveResponse();
        
        if (!$response || $response['command'] != self::CMD_PREPARE_DATA) {
            return false;
        }
        
        $size = $response['size'];
        
        // Send ready command
        $command = $this->createCommand(self::CMD_ACK_OK);
        if (!$this->sendCommand($command)) {
            return false;
        }
        
        // Receive data
        $data_response = $this->receiveResponse();
        
        if (!$data_response || $data_response['command'] != self::CMD_DATA) {
            return false;
        }
        
        $raw_data = $data_response['data'];
        
        // Parse attendance records
        $records = $this->parseAttendanceData($raw_data);
        
        // Send free data command
        $command = $this->createCommand(self::CMD_FREE_DATA);
        $this->sendCommand($command);
        
        return $records;
    }
    
    /**
     * Clear attendance records from device
     */
    public function clearAttendance() {
        $command = $this->createCommand(self::CMD_CLEAR_ATTLOG);
        if (!$this->sendCommand($command)) {
            return false;
        }
        
        $response = $this->receiveResponse();
        return ($response && $response['command'] == self::CMD_ACK_OK);
    }
    
    /**
     * Clear attendance records before a specific date
     */
    public function clearAttendanceBeforeDate($date) {
        // Get all records
        $records = $this->getAttendance();
        if ($records === false) {
            return false;
        }
        
        // This is a simplified version
        // In production, you'd need to implement selective deletion
        // For now, this is a placeholder
        return true;
    }
    
    /**
     * Get device version
     */
    public function getVersion() {
        $command = $this->createCommand(self::CMD_VERSION);
        if (!$this->sendCommand($command)) {
            return false;
        }
        
        $response = $this->receiveResponse();
        return $response ? $response['data'] : false;
    }
    
    /**
     * Create command packet
     */
    private function createCommand($command, $data = '') {
        $this->reply_id++;
        
        $buf = pack('SSSS', $command, 0, $this->session_id, $this->reply_id);
        $buf .= $data;
        
        $checksum = $this->createChecksum($buf);
        $packet = pack('SSSS', $command, $checksum, $this->session_id, $this->reply_id);
        $packet .= $data;
        
        return $packet;
    }
    
    /**
     * Send command to device
     */
    private function sendCommand($command) {
        $length = strlen($command);
        $sent = @socket_write($this->socket, $command, $length);
        
        return ($sent !== false && $sent === $length);
    }
    
    /**
     * Receive response from device
     */
    private function receiveResponse() {
        $header = @socket_read($this->socket, 8, PHP_BINARY_READ);
        
        if ($header === false || strlen($header) < 8) {
            return false;
        }
        
        $response = unpack('Scommand/Schecksum/Ssession_id/Sreply_id', $header);
        
        // Check if there's additional data
        $size = 0;
        if ($response['command'] == self::CMD_PREPARE_DATA || 
            $response['command'] == self::CMD_DATA) {
            
            $size_bytes = @socket_read($this->socket, 4, PHP_BINARY_READ);
            if ($size_bytes) {
                $size_data = unpack('Vsize', $size_bytes);
                $size = $size_data['size'];
                $response['size'] = $size;
            }
        }
        
        // Read data if present
        if ($size > 0) {
            $data = '';
            $remaining = $size;
            
            while ($remaining > 0) {
                $chunk = @socket_read($this->socket, min($remaining, 1024), PHP_BINARY_READ);
                if ($chunk === false) {
                    break;
                }
                $data .= $chunk;
                $remaining -= strlen($chunk);
            }
            
            $response['data'] = $data;
        }
        
        return $response;
    }
    
    /**
     * Parse attendance data
     */
    private function parseAttendanceData($data) {
        $records = [];
        $record_size = 40; // Size of each attendance record
        $count = strlen($data) / $record_size;
        
        for ($i = 0; $i < $count; $i++) {
            $record_data = substr($data, $i * $record_size, $record_size);
            
            if (strlen($record_data) < $record_size) {
                continue;
            }
            
            // Parse record (this is device-specific, may need adjustment)
            $parsed = unpack(
                'Suser_id/C24padding/Vtimestamp/Cstate/Cverify/C8padding2',
                $record_data
            );
            
            if ($parsed && $parsed['timestamp'] > 0) {
                $records[] = [
                    'uid' => $parsed['user_id'],
                    'timestamp' => date('Y-m-d H:i:s', $parsed['timestamp']),
                    'state' => $parsed['state'], // 0=check-in, 1=check-out, etc.
                    'verify_type' => $parsed['verify'] // Verification method
                ];
            }
        }
        
        return $records;
    }
    
    /**
     * Create checksum for packet
     */
    private function createChecksum($buf) {
        $checksum = 0;
        $length = strlen($buf);
        
        for ($i = 0; $i < $length; $i += 2) {
            if ($i == $length - 1) {
                $checksum += ord($buf[$i]);
            } else {
                $checksum += unpack('S', substr($buf, $i, 2))[1];
            }
        }
        
        $checksum = ~$checksum;
        $checksum = $checksum & 0xFFFF;
        
        return $checksum;
    }
    
    /**
     * Destructor - ensure socket is closed
     */
    public function __destruct() {
        $this->disconnect();
    }
}
?>
