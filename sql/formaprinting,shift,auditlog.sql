-- Create forma_printing table
Create table shifts (

   id SERIAL PRIMARY KEY,
   name VARCHAR(20),
  
	 remarks TEXT,
    status BOOLEAN DEFAULT true
);

CREATE TABLE forma_printing (
    id SERIAL PRIMARY KEY,
    date_nep VARCHAR(20),
    date_eng VARCHAR(20),
    name VARCHAR(255) NOT NULL,
    fiscal_year_id INTEGER NOT NULL REFERENCES fiscal_years(id) ,
    jt_id INTEGER REFERENCES job_ticket(id),
    jtd_id INTEGER REFERENCES job_ticket_details(id),
    jtd_targetqty BIGINT,
    fp_printqty BIGINT,
    fp_remainqty BIGINT,
    supervisor_id INTEGER REFERENCES users(id),
    created_by INTEGER NOT NULL REFERENCES users(id),
    operator_id INTEGER REFERENCES users(id),
    incharge_id INTEGER REFERENCES users(id),
    shift_id INTEGER REFERENCES shifts(id),
    machine_id INTEGER REFERENCES machines(id),
    remarks TEXT,
    status BOOLEAN DEFAULT true,
    created_date TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER REFERENCES users(id),
    delete_by INTEGER REFERENCES users(id),
    description TEXT
);

-- Create indexes for forma_printing
CREATE INDEX idx_forma_printing_fiscal_year ON forma_printing(fiscal_year_id);
CREATE INDEX idx_forma_printing_jt_id ON forma_printing(jt_id);
CREATE INDEX idx_forma_printing_jtd_id ON forma_printing(jtd_id);
CREATE INDEX idx_forma_printing_machine_id ON forma_printing(machine_id);
CREATE INDEX idx_forma_printing_created_date ON forma_printing(created_date);
CREATE INDEX idx_forma_printing_status ON forma_printing(status);

-- Create audit_log table
CREATE TABLE audit_log (
    id SERIAL PRIMARY KEY,
    module_name VARCHAR(100) NOT NULL,
    table_name VARCHAR(100) NOT NULL,
    record_id INTEGER NOT NULL,
    action VARCHAR(20) NOT NULL, -- 'create', 'update', 'delete'
    field_name VARCHAR(100),
    old_value TEXT,
    new_value TEXT,
    changed_by INTEGER NOT NULL REFERENCES users(id),
    changed_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT
);

-- Create indexes for audit_log
CREATE INDEX idx_audit_log_module ON audit_log(module_name);
CREATE INDEX idx_audit_log_table ON audit_log(table_name);
CREATE INDEX idx_audit_log_record ON audit_log(record_id);
CREATE INDEX idx_audit_log_action ON audit_log(action);
CREATE INDEX idx_audit_log_changed_at ON audit_log(changed_at);

-- Create function to log changes
CREATE OR REPLACE FUNCTION log_forma_printing_changes()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO audit_log (
            module_name, table_name, record_id, action, 
            field_name, old_value, new_value, changed_by,
            ip_address, user_agent
        ) VALUES (
            'Forma Printing', 'forma_printing', NEW.id, 'create',
            NULL, NULL, NULL, NEW.created_by,
            NULL, NULL
        );
    ELSIF TG_OP = 'UPDATE' THEN
        IF OLD.name IS DISTINCT FROM NEW.name THEN
            INSERT INTO audit_log (
                module_name, table_name, record_id, action, 
                field_name, old_value, new_value, changed_by,
                ip_address, user_agent
            ) VALUES (
                'Forma Printing', 'forma_printing', NEW.id, 'update',
                'name', OLD.name, NEW.name, NEW.updated_by,
                NULL, NULL
            );
        END IF;
        
        -- Add similar checks for other fields you want to track
        -- Example for status:
        IF OLD.status IS DISTINCT FROM NEW.status THEN
            INSERT INTO audit_log (
                module_name, table_name, record_id, action, 
                field_name, old_value, new_value, changed_by,
                ip_address, user_agent
            ) VALUES (
                'Forma Printing', 'forma_printing', NEW.id, 'update',
                'status', OLD.status::text, NEW.status::text, NEW.updated_by,
                NULL, NULL
            );
        END IF;
        
    ELSIF TG_OP = 'DELETE' THEN
        INSERT INTO audit_log (
            module_name, table_name, record_id, action, 
            field_name, old_value, new_value, changed_by,
            ip_address, user_agent
        ) VALUES (
            'Forma Printing', 'forma_printing', OLD.id, 'delete',
            NULL, NULL, NULL, OLD.delete_by,
            NULL, NULL
        );
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Create similar function for deno table
CREATE OR REPLACE FUNCTION log_deno_changes()
RETURNS TRIGGER AS $$
BEGIN
    -- Implementation similar to above for deno table
END;
$$ LANGUAGE plpgsql;

-- Create triggers
CREATE TRIGGER forma_printing_audit
AFTER INSERT OR UPDATE OR DELETE ON forma_printing
FOR EACH ROW EXECUTE FUNCTION log_forma_printing_changes();

CREATE TRIGGER deno_audit
AFTER INSERT OR UPDATE OR DELETE ON deno
FOR EACH ROW EXECUTE FUNCTION log_deno_changes();