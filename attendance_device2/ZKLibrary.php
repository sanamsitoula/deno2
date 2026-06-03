<?php
/**
 * ZKLibrary - Enhanced ZKTeco Device Communication Library
 * 
 * Features:
 * - Connect/Disconnect to device
 * - Get/Set users (with fingerprint templates)
 * - Get attendance logs
 * - Push data to device
 * - Clear data
 * - Device information retrieval
 * - Real-time operations
 * 
 * @version 2.0
 */

class ZKLibrary {
    // Connection properties
    private $ip;
    private $port;
    private $socket;
    private $timeout_sec = 5;
    private $timeout_usec = 500000;
    
    // Session properties
    private $session_id = 0;
    private $data_recv = '';
    private $reply_id = 0;
    
    // Data storage
    private $userdata = [];
    private $attendancedata = [];
    
    // Constants
    const USHRT_MAX = 65535;
    
    // Commands
    const CMD_CONNECT = 1000;
    const CMD_EXIT = 1001;
    const CMD_ENABLEDEVICE = 1002;
    const CMD_DISABLEDEVICE = 1003;
    const CMD_RESTART = 1004;
    const CMD_POWEROFF = 1005;
    const CMD_VERSION = 1100;
    const CMD_GET_TIME = 201;
    const CMD_SET_TIME = 202;
    
    // Data commands
    const CMD_USERTEMP_RRQ = 9;
    const CMD_USERTEMP_WRQ = 10;
    const CMD_ATTLOG_RRQ = 13;
    const CMD_CLEAR_DATA = 14;
    const CMD_CLEAR_ATTLOG = 15;
    const CMD_DELETE_USER = 18;
    const CMD_CLEAR_ADMIN = 20;
    const CMD_SET_USER = 8;
    const CMD_PREPARE_DATA = 1500;
    const CMD_DATA = 1501;
    const CMD_FREE_DATA = 1502;
    
    // Device info commands
    const CMD_DEVICE = 11;
    const CMD_GET_FREE_SIZES = 50;
    const CMD_STARTENROLL = 61;
    
    // Response codes
    const CMD_ACK_OK = 2000;
    const CMD_ACK_ERROR = 2001;
    const CMD_ACK_DATA = 2002;
    
    // User roles
    const LEVEL_USER = 0;
    const LEVEL_ADMIN = 14;
    
    /**
     * Constructor
     */
    public function __construct($ip, $port = 4370) {
        $this->ip = $ip;
        $this->port = $port;
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        
        if ($this->socket === false) {
            throw new Exception("Failed to create socket: " . socket_strerror(socket_last_error()));
        }
        
        $timeout = ['sec' => $this->timeout_sec, 'usec' => $this->timeout_usec];
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
    }
    
    /**
     * Set timeout
     */
    public function setTimeout($seconds) {
        $this->timeout_sec = $seconds;
        $timeout = ['sec' => $this->timeout_sec, 'usec' => $this->timeout_usec];
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
    }
    
    /**
     * Connect to device
     */
    public function connect() {
        $command = self::CMD_CONNECT;
        $command_string = '';
        $chksum = 0;
        $session_id = 0;
        $reply_id = -1 + self::USHRT_MAX;
        
        $buf = $this->createHeader($command, $chksum, $session_id, $reply_id, $command_string);
        
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            
            if (strlen($this->data_recv) > 0) {
                $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6', substr($this->data_recv, 0, 8));
                $this->session_id = hexdec($u['h6'].$u['h5']);
                $this->reply_id = hexdec($u['h8'].$u['h7']);
                return $this->checkValid($this->data_recv);
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Disconnect from device
     */
    public function disconnect() {
        $command = self::CMD_EXIT;
        $command_string = '';
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            socket_close($this->socket);
            return $this->checkValid($this->data_recv);
        } catch (Exception $e) {
            socket_close($this->socket);
            return false;
        }
    }
    
    /**
     * Disable device (freeze for operations)
     */
    public function disableDevice() {
        $command = self::CMD_DISABLEDEVICE;
        $command_string = chr(0).chr(0);
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return $this->checkValid($this->data_recv);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Enable device (unfreeze)
     */
    public function enableDevice() {
        $command = self::CMD_ENABLEDEVICE;
        $command_string = '';
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return $this->checkValid($this->data_recv);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get all users from device
     * Returns array: [uid => [userid, name, cardno, uid, role, password]]
     */
    public function getUser() {
        $command = self::CMD_USERTEMP_RRQ;
        $command_string = chr(5);
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        $this->userdata = [];
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            
            $size = $this->getSizeFromPrepare();
            
            if ($size) {
                $bytes = $size;
                while ($bytes > 0) {
                    @socket_recvfrom($this->socket, $data_recv, 1032, 0, $this->ip, $this->port);
                    array_push($this->userdata, $data_recv);
                    $bytes -= 1024;
                }
                
                // Receive final ACK
                @socket_recvfrom($this->socket, $data_recv, 1024, 0, $this->ip, $this->port);
            }
            
            return $this->parseUserData();
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Set/Add user to device
     */
    public function setUser($uid, $userid, $name, $password = '', $role = self::LEVEL_USER) {
        $command = self::CMD_SET_USER;
        
        // Pack user data (72 bytes total)
        $command_string = pack(
            'axaa8a28aa7xa8a16',
            chr($uid),           // UID (2 bytes)
            chr($role),          // Role (1 byte)
            $password,           // Password (8 bytes)
            $name,               // Name (28 bytes)
            chr(1),              // Enabled flag
            '',                  // Reserved (7 bytes)
            $userid,             // User ID string (8 bytes)
            ''                   // Reserved (16 bytes)
        );
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return $this->checkValid($this->data_recv);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Delete user from device
     */
    public function deleteUser($uid) {
        $command = self::CMD_DELETE_USER;
        $command_string = pack('S', $uid);
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return $this->checkValid($this->data_recv);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get attendance logs
     * Returns array: [uid, id, state, timestamp]
     */
    public function getAttendance() {
        $command = self::CMD_ATTLOG_RRQ;
        $command_string = '';
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        $this->attendancedata = [];
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            
            $size = $this->getSizeFromPrepare();
            
            if ($size) {
                $bytes = $size;
                while ($bytes > 0) {
                    @socket_recvfrom($this->socket, $data_recv, 1032, 0, $this->ip, $this->port);
                    array_push($this->attendancedata, $data_recv);
                    $bytes -= 1024;
                }
                
                // Receive final ACK
                @socket_recvfrom($this->socket, $data_recv, 1024, 0, $this->ip, $this->port);
            }
            
            return $this->parseAttendanceData();
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Clear all attendance logs
     */
    public function clearAttendance() {
        $command = self::CMD_CLEAR_ATTLOG;
        $command_string = '';
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return $this->checkValid($this->data_recv);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Clear all user data
     */
    public function clearUser() {
        $command = self::CMD_CLEAR_DATA;
        $command_string = '';
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return $this->checkValid($this->data_recv);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get device version
     */
    public function version() {
        $command = self::CMD_VERSION;
        $command_string = '';
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return substr($this->data_recv, 8);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get device name
     */
    public function deviceName() {
        return $this->getDeviceInfo('~DeviceName');
    }
    
    /**
     * Get serial number
     */
    public function serialNumber() {
        return $this->getDeviceInfo('~SerialNumber');
    }
    
    /**
     * Get platform
     */
    public function platform() {
        return $this->getDeviceInfo('~Platform');
    }
    
    /**
     * Get OS version
     */
    public function osVersion() {
        return $this->getDeviceInfo('~OS');
    }
    
    /**
     * Get device time
     */
    public function getTime() {
        $command = self::CMD_GET_TIME;
        $command_string = '';
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            
            $time_data = substr($this->data_recv, 8);
            $timestamp = hexdec($this->reverseHex(bin2hex($time_data)));
            
            return $this->decodeTime($timestamp);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Set device time
     */
    public function setTime($datetime) {
        $command = self::CMD_SET_TIME;
        $timestamp = $this->encodeTime($datetime);
        $command_string = pack('I', $timestamp);
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return $this->checkValid($this->data_recv);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get free sizes (capacity info)
     */
    public function getFreeSizes() {
        $command = self::CMD_GET_FREE_SIZES;
        $command_string = '';
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            
            $data = substr($this->data_recv, 8);
            $info = unpack('V*', $data);
            
            return [
                'users' => $info[1] ?? 0,
                'logs' => $info[2] ?? 0,
                'capacity_users' => $info[3] ?? 0,
                'capacity_logs' => $info[4] ?? 0
            ];
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Start enrollment for user
     */
    public function enrollUser($userid) {
        $command = self::CMD_STARTENROLL;
        $command_string = pack("a*", $userid);
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return $this->checkValid($this->data_recv);
        } catch (Exception $e) {
            return false;
        }
    }
    
    // ========================================
    // PRIVATE HELPER METHODS
    // ========================================
    
    private function createCommand($command, $command_string) {
        $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->data_recv, 0, 8));
        $reply_id = hexdec($u['h8'].$u['h7']);
        
        return $this->createHeader($command, 0, $this->session_id, $reply_id, $command_string);
    }
    
    private function createHeader($command, $chksum, $session_id, $reply_id, $command_string) {
        $buf = pack('SSSS', $command, $chksum, $session_id, $reply_id) . $command_string;
        $buf = unpack('C' . (8 + strlen($command_string)) . 'c', $buf);
        
        $u = unpack('S', $this->createChkSum($buf));
        $chksum = is_array($u) ? reset($u) : $u;
        
        $reply_id += 1;
        if ($reply_id >= self::USHRT_MAX) {
            $reply_id -= self::USHRT_MAX;
        }
        
        $buf = pack('SSSS', $command, $chksum, $session_id, $reply_id);
        return $buf . $command_string;
    }
    
    private function createChkSum($p) {
        $l = count($p);
        $chksum = 0;
        $i = $l;
        $j = 1;
        
        while ($i > 1) {
            $u = unpack('S', pack('C2', $p['c'.$j], $p['c'.($j+1)]));
            $chksum += $u[1];
            
            if ($chksum > self::USHRT_MAX) {
                $chksum -= self::USHRT_MAX;
            }
            
            $i -= 2;
            $j += 2;
        }
        
        if ($i) {
            $chksum = $chksum + $p['c'.strval(count($p))];
        }
        
        while ($chksum > self::USHRT_MAX) {
            $chksum -= self::USHRT_MAX;
        }
        
        $chksum = ($chksum > 0) ? -$chksum : abs($chksum);
        $chksum -= 1;
        
        while ($chksum < 0) {
            $chksum += self::USHRT_MAX;
        }
        
        return pack('S', $chksum);
    }
    
    private function checkValid($reply) {
        $u = unpack('H2h1/H2h2', substr($reply, 0, 8));
        $command = hexdec($u['h2'].$u['h1']);
        return ($command == self::CMD_ACK_OK);
    }
    
    private function getSizeFromPrepare() {
        $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->data_recv, 0, 8));
        $command = hexdec($u['h2'].$u['h1']);
        
        if ($command == self::CMD_PREPARE_DATA) {
            $u = unpack('H2h1/H2h2/H2h3/H2h4', substr($this->data_recv, 8, 4));
            return hexdec($u['h4'].$u['h3'].$u['h2'].$u['h1']);
        }
        
        return false;
    }
    
    private function parseUserData() {
        $users = [];
        
        if (count($this->userdata) > 0) {
            // Remove first 4 bytes from each packet (except first)
            for ($x = 0; $x < count($this->userdata); $x++) {
                if ($x > 0) {
                    $this->userdata[$x] = substr($this->userdata[$x], 8);
                }
            }
            
            $userdata = implode('', $this->userdata);
            $userdata = substr($userdata, 11);
            
            while (strlen($userdata) > 72) {
                $u = unpack('H144', substr($userdata, 0, 72));
                
                $u1 = hexdec(substr($u[1], 2, 2));
                $u2 = hexdec(substr($u[1], 4, 2));
                $uid = $u1 + ($u2 * 256);
                
                $cardno = hexdec(substr($u[1], 78, 2).substr($u[1], 76, 2).substr($u[1], 74, 2).substr($u[1], 72, 2));
                $role = hexdec(substr($u[1], 4, 4));
                $password = hex2bin(substr($u[1], 8, 16));
                $name = hex2bin(substr($u[1], 24, 74));
                $userid = hex2bin(substr($u[1], 98, 72));
                
                // Clean up data
                $password = explode(chr(0), $password, 2)[0];
                $userid = explode(chr(0), $userid, 2)[0];
                $name = explode(chr(0), $name, 3)[0];
                $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
                $cardno = str_pad($cardno, 11, '0', STR_PAD_LEFT);
                
                if (empty($name)) {
                    $name = $uid;
                }
                
                $users[$uid] = [
                    'userid' => $userid,
                    'name' => $name,
                    'cardno' => $cardno,
                    'uid' => $uid,
                    'role' => intval($role),
                    'password' => $password
                ];
                
                $userdata = substr($userdata, 72);
            }
        }
        
        return $users;
    }
    
    private function parseAttendanceData() {
        $attendance = [];
        
        if (count($this->attendancedata) > 0) {
            // Remove first 4 bytes from each packet (except first)
            for ($x = 0; $x < count($this->attendancedata); $x++) {
                if ($x > 0) {
                    $this->attendancedata[$x] = substr($this->attendancedata[$x], 8);
                }
            }
            
            $attendancedata = implode('', $this->attendancedata);
            $attendancedata = substr($attendancedata, 10);
            
            while (strlen($attendancedata) > 40) {
                $u = unpack('H78', substr($attendancedata, 0, 39));
                
                $u1 = hexdec(substr($u[1], 4, 2));
                $u2 = hexdec(substr($u[1], 6, 2));
                $uid = $u1 + ($u2 * 256);
                
                $id = intval(str_replace("\0", '', hex2bin(substr($u[1], 6, 8))));
                $state = hexdec(substr($u[1], 56, 2));
                $timestamp = $this->decodeTime(hexdec($this->reverseHex(substr($u[1], 58, 8))));
                
                $attendance[] = [
                    'uid' => $uid,
                    'id' => $id,
                    'state' => $state,
                    'timestamp' => $timestamp
                ];
                
                $attendancedata = substr($attendancedata, 40);
            }
        }
        
        return $attendance;
    }
    
    private function getDeviceInfo($command_string) {
        $command = self::CMD_DEVICE;
        
        $buf = $this->createCommand($command, $command_string);
        socket_sendto($this->socket, $buf, strlen($buf), 0, $this->ip, $this->port);
        
        try {
            @socket_recvfrom($this->socket, $this->data_recv, 1024, 0, $this->ip, $this->port);
            return substr($this->data_recv, 8);
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function reverseHex($hexstr) {
        $tmp = '';
        for ($i = strlen($hexstr); $i >= 0; $i--) {
            $tmp .= substr($hexstr, $i, 2);
            $i--;
        }
        return $tmp;
    }
    
    private function encodeTime($datetime) {
        $dt = new DateTime($datetime);
        $year = (int)$dt->format('Y');
        $month = (int)$dt->format('m');
        $day = (int)$dt->format('d');
        $hour = (int)$dt->format('H');
        $minute = (int)$dt->format('i');
        $second = (int)$dt->format('s');
        
        $d = (($year % 100) * 12 * 31 + (($month - 1) * 31) + $day - 1) *
             (24 * 60 * 60) + ($hour * 60 + $minute) * 60 + $second;
        
        return $d;
    }
    
    private function decodeTime($t) {
        $second = $t % 60;
        $t = floor($t / 60);
        
        $minute = $t % 60;
        $t = floor($t / 60);
        
        $hour = $t % 24;
        $t = floor($t / 24);
        
        $day = $t % 31 + 1;
        $t = floor($t / 31);
        
        $month = $t % 12 + 1;
        $t = floor($t / 12);
        
        $year = floor($t + 2000);
        
        return date("Y-m-d H:i:s", strtotime($year.'-'.$month.'-'.$day.' '.$hour.':'.$minute.':'.$second));
    }
    
    public function __destruct() {
        if (is_resource($this->socket)) {
            @socket_close($this->socket);
        }
    }
}
