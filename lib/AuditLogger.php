<?php
class AuditLogger {
    private $conn;
    private $moduleName;
    private $tableName;
    
    public function __construct(PDO $conn, string $moduleName, string $tableName) {
        $this->conn = $conn;
        $this->moduleName = $moduleName;
        $this->tableName = $tableName;
    }
    
    public function prepareForAudit(): void {
        $user_id = $_SESSION['user_id'] ?? 40;
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Verify the user exists in database
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $valid_user = $stmt->fetch();

        if (!$valid_user) {
            // Find a default user (like 'system' user)
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = 'system' LIMIT 1");
            $stmt->execute();
            $default_user = $stmt->fetch();
            
            if (!$default_user) {
                // Get any user as fallback
                $stmt = $this->conn->prepare("SELECT id FROM users LIMIT 1");
                $stmt->execute();
                $default_user = $stmt->fetch();
                
                if (!$default_user) {
                    throw new Exception("No users exist in the system");
                }
            }
            
            $user_id = $default_user['id'];
        }

        // Set the session variables
        $this->conn->exec("SET LOCAL app.current_user_id = " . $this->conn->quote($user_id));
        $this->conn->exec("SET LOCAL app.client_ip = " . $this->conn->quote($ip_address));
        $this->conn->exec("SET LOCAL app.user_agent = " . $this->conn->quote($user_agent));
    }
    
    public static function createTriggerFunction(PDO $conn): void {
        $sql = <<<SQL
        CREATE OR REPLACE FUNCTION public.log_changes()
            RETURNS trigger
            LANGUAGE plpgsql
        AS \$BODY$
        DECLARE
            v_action TEXT;
            v_user_id INTEGER;
            v_ip_address VARCHAR(45);
            v_user_agent TEXT;
            v_default_user_id INTEGER;
        BEGIN
            -- First, find a valid default user ID that exists in the users table
            SELECT id INTO v_default_user_id FROM users WHERE username = 'system' LIMIT 1;
            IF NOT FOUND THEN
                SELECT id INTO v_default_user_id FROM users LIMIT 1;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'No users exist in the system';
                END IF;
            END IF;

            -- Determine the action type
            IF TG_OP = 'INSERT' THEN
                v_action := 'CREATE';
            ELSIF TG_OP = 'UPDATE' THEN
                v_action := 'UPDATE';
            ELSIF TG_OP = 'DELETE' THEN
                v_action := 'DELETE';
            END IF;
            
            -- Get user information with robust error handling
            BEGIN
                v_user_id := NULLIF(current_setting('app.current_user_id', TRUE), '')::INTEGER;
            EXCEPTION WHEN OTHERS THEN
                v_user_id := NULL;
            END;
            
            BEGIN
                v_ip_address := NULLIF(current_setting('app.client_ip', TRUE), '');
            EXCEPTION WHEN OTHERS THEN
                v_ip_address := '0.0.0.0';
            END;
            
            BEGIN
                v_user_agent := NULLIF(current_setting('app.user_agent', TRUE), '');
            EXCEPTION WHEN OTHERS THEN
                v_user_agent := 'Unknown';
            END;
            
            -- Ensure we have a valid user ID
            IF v_user_id IS NULL THEN
                v_user_id := v_default_user_id;
            END IF;
            
            -- Verify the user exists
            PERFORM 1 FROM users WHERE id = v_user_id;
            IF NOT FOUND THEN
                v_user_id := v_default_user_id;
            END IF;
            
            -- For INSERT actions
            IF TG_OP = 'INSERT' THEN
                INSERT INTO audit_log (
                    module_name,
                    table_name,
                    record_id,
                    action,
                    changed_by,
                    ip_address,
                    user_agent
                ) VALUES (
                    TG_ARGV[0],
                    TG_ARGV[1],
                    NEW.id,
                    v_action,
                    v_user_id,
                    v_ip_address,
                    v_user_agent
                );
            
            -- For UPDATE actions - track individual field changes
            ELSIF TG_OP = 'UPDATE' THEN
                -- Track all fields that changed
                IF NEW.book_code IS DISTINCT FROM OLD.book_code THEN
                    INSERT INTO audit_log (
                        module_name,
                        table_name,
                        record_id,
                        action,
                        field_name,
                        old_value,
                        new_value,
                        changed_by,
                        ip_address,
                        user_agent
                    ) VALUES (
                        TG_ARGV[0],
                        TG_ARGV[1],
                        NEW.id,
                        v_action,
                        'book_code',
                        OLD.book_code::TEXT,
                        NEW.book_code::TEXT,
                        v_user_id,
                        v_ip_address,
                        v_user_agent
                    );
                END IF;
                
                -- Add similar blocks for other fields you want to track
                
            -- For DELETE actions
            ELSIF TG_OP = 'DELETE' THEN
                INSERT INTO audit_log (
                    module_name,
                    table_name,
                    record_id,
                    action,
                    changed_by,
                    ip_address,
                    user_agent
                ) VALUES (
                    TG_ARGV[0],
                    TG_ARGV[1],
                    OLD.id,
                    v_action,
                    v_user_id,
                    v_ip_address,
                    v_user_agent
                );
            END IF;
            
            RETURN (CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END);
        END;
        \$BODY$;
        SQL;
        
        $conn->exec($sql);
    }
    
    public static function setupTableTriggers(PDO $conn, string $moduleName, string $tableName): void {
        // Create or replace the triggers
        $triggers = [
            'insert' => 'AFTER INSERT',
            'update' => 'AFTER UPDATE',
            'delete' => 'AFTER DELETE'
        ];
        
        foreach ($triggers as $name => $timing) {
            $sql = "CREATE OR REPLACE TRIGGER trg_{$tableName}_{$name}
                    {$timing} ON {$tableName}
                    FOR EACH ROW EXECUTE FUNCTION log_changes('{$moduleName}', '{$tableName}')";
            $conn->exec($sql);
        }
    }
}