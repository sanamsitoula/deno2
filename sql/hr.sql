-- Create the database
CREATE DATABASE employee_management_system;

-- Connect to the database
\c employee_management_system;

-- Create departments table
CREATE TABLE department (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sub_department_name VARCHAR(255),
    status BOOLEAN DEFAULT TRUE,
    remarks VARCHAR(255),
    display_order INTEGER DEFAULT 0,
    is_technical BOOLEAN DEFAULT FALSE
  
);

-- Create levels table
CREATE TABLE level (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    status BOOLEAN DEFAULT TRUE,
    display_order INTEGER DEFAULT 0
  
);

-- Create designations table
CREATE TABLE designation (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
 
    status BOOLEAN DEFAULT TRUE,
    is_technical BOOLEAN DEFAULT FALSE
   
);

-- Create employees table
CREATE TABLE employee (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    attendance_id VARCHAR(100),
    emp_status VARCHAR(50) DEFAULT 'ACTIVE',
    emp_type VARCHAR(50),
    name VARCHAR(255) NOT NULL,
    citizenship_no VARCHAR(100),
    national_id_card_no VARCHAR(100),
    mobile_number VARCHAR(20),
    email VARCHAR(255),
    full_address TEXT,
    join_date VARCHAR(20),
    retirement_date VARCHAR(20),
    initial_appointment_date VARCHAR(20),
    dob VARCHAR(20),
    gender VARCHAR(20),
    picture VARCHAR(255),
    designation_id INTEGER REFERENCES designation(id),
	 level_id INTEGER REFERENCES level(id),
	  department_id INTEGER REFERENCES department(id),
    created_by INTEGER,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_by INTEGER,
    deleted_date TIMESTAMP
);

-- Create employee_family table
CREATE TABLE employee_family (
    id SERIAL PRIMARY KEY,
    emp_id INTEGER REFERENCES employee(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    relation VARCHAR(100) NOT NULL,
    contact VARCHAR(20),
    remarks TEXT,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create education_details table
CREATE TABLE education_details (
    id SERIAL PRIMARY KEY,
    emp_id INTEGER REFERENCES employee(id) ON DELETE CASCADE,
    institution_name VARCHAR(255) NOT NULL,
    degree_name VARCHAR(255) NOT NULL,
    university VARCHAR(255),
    marks DECIMAL(5,2),
    remarks TEXT,
    status BOOLEAN DEFAULT TRUE,
    display_order INTEGER DEFAULT 0,
    completion_year INTEGER,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create employee_designation table for transfers and promotions
CREATE TABLE employee_designation (
    id SERIAL PRIMARY KEY,
    emp_id INTEGER REFERENCES employee(id) ON DELETE CASCADE,
    date_of_join DATE NOT NULL,
    date_of_attendance VARCHAR(20),
    date_of_left VARCHAR(20),
    no_of_days INTEGER,
    designation_id INTEGER REFERENCES designation(id),
    level_id INTEGER REFERENCES level(id),
    department_id INTEGER REFERENCES department(id),
    status VARCHAR(50) DEFAULT 'ACTIVE',
    display_order INTEGER DEFAULT 0,
    remarks TEXT,
    description TEXT,
    documents VARCHAR(255),
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for better performance
CREATE INDEX idx_employee_code ON employee(code);
CREATE INDEX idx_employee_email ON employee(email);
CREATE INDEX idx_employee_mobile ON employee(mobile_number);
CREATE INDEX idx_employee_designation ON employee(designation_id);
CREATE INDEX idx_emp_family_emp_id ON employee_family(emp_id);
CREATE INDEX idx_education_emp_id ON education_details(emp_id);
CREATE INDEX idx_emp_designation_emp_id ON employee_designation(emp_id);
CREATE INDEX idx_emp_designation_dates ON employee_designation(date_of_join, date_of_left);

-- Insert sample data for testing
INSERT INTO department (name, sub_department_name, is_technical, display_order) VALUES
('IT', 'Software Development', TRUE, 1),
('HR', 'Recruitment', FALSE, 2),
('Finance', 'Accounts', FALSE, 3),
('Operations', 'Production', TRUE, 4);

INSERT INTO level (name, display_order) VALUES
('Entry Level', 1),
('Junior', 2),
('Mid Level', 3),
('Senior', 4),
('Manager', 5),
('Director', 6);

INSERT INTO designation (name, department_id, is_technical) VALUES
('Software Engineer', 1, TRUE),
('Senior Developer', 1, TRUE),
('IT Manager', 1, TRUE),
('HR Executive', 2, FALSE),
('Finance Manager', 3, FALSE),
('Operations Manager', 4, TRUE);

-- Create a function to update the updated_date timestamp
CREATE OR REPLACE FUNCTION update_updated_date()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_date = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create triggers for automatic updated_date
CREATE TRIGGER trigger_update_employee_date
    BEFORE UPDATE ON employee
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_date();

CREATE TRIGGER trigger_update_department_date
    BEFORE UPDATE ON department
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_date();

CREATE TRIGGER trigger_update_designation_date
    BEFORE UPDATE ON designation
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_date();

CREATE TRIGGER trigger_update_level_date
    BEFORE UPDATE ON level
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_date();

CREATE TRIGGER trigger_update_emp_designation_date
    BEFORE UPDATE ON employee_designation
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_date();

-- Create view for employee details with current designation
CREATE VIEW employee_current_details AS
SELECT 
    e.*,
    d.name as designation_name,
    dep.name as department_name,
    l.name as level_name
FROM employee e
LEFT JOIN designation d ON e.designation_id = d.id
LEFT JOIN department dep ON d.department_id = dep.id
LEFT JOIN level l ON e.designation_id = d.id;

-- Comments for better documentation
COMMENT ON TABLE employee IS 'Stores basic employee information and personal details';
COMMENT ON TABLE employee_family IS 'Stores family details of employees';
COMMENT ON TABLE education_details IS 'Stores educational qualifications of employees';
COMMENT ON TABLE employee_designation IS 'Tracks employee designation history, transfers, and promotions';
COMMENT ON TABLE designation IS 'Contains all available designations in the organization';
COMMENT ON TABLE department IS 'Contains department structure of the organization';
COMMENT ON TABLE level IS 'Contains hierarchy levels within the organization';