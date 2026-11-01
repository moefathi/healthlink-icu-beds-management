# HealthLink – ICU Beds & Hospital Capacity Management

## Overview
HealthLink is a multi-database healthcare management system developed to manage ICU beds, hospital capacity, patient stays, medical information, equipment allocations, daily charges, and inter-hospital transfers.

The system integrates three database technologies, each serving a different purpose:

- **Oracle Database** manages the main structured healthcare data and enforces business rules.
- **MongoDB** stores semi-structured medical information associated with ICU stays.
- **Neo4j** models hospital connections, transfer history, and routes between hospitals.

A web application built using PHP, HTML, CSS, and JavaScript connects the databases and provides separate interfaces for administrators and hospital staff.

## Features
- Hospital and ICU bed management.
- Patient records and ICU stay management.
- ICU bed availability tracking.
- Medical equipment allocation and availability management.
- Daily charges and billing management.
- Inter-hospital transfer management.
- Medical information storage for ICU stays.
- Hospital route visualization and shortest-path searching.
- Administrative access to system-wide information.
- Hospital staff access to their hospital's operations.
- Automated database auditing and business rule enforcement.

## Database Implementation

### 1. Oracle Database
Oracle serves as the main relational database and manages the system's structured data.

Implemented features include:

- Relational database design, ERD, and physical database structure.
- Tables, primary keys, foreign keys, and integrity constraints.
- Updatable views with `WITH CHECK OPTION`.
- Summary queries and materialized views.
- PL/SQL procedures and functions.
- Database triggers for business rule enforcement.
- PL/SQL packages for billing operations.
- Automated audit logging.

### 2. MongoDB
MongoDB stores semi-structured medical information associated with ICU stays.

The `Stay_Info` collection contains documents linked to Oracle records through `stayId`, with optional information such as:

- Clinical notes.
- Monitoring logs.
- Incident notes.

The implementation also includes document insertion, counting, filtering, and sorting operations.

### 3. Neo4j
Neo4j represents hospitals and their relationships using a graph database.

Implemented features include:

- Hospital nodes.
- `TRANSFERRED_STAY_TO` relationships representing historical hospital transfers.
- `ROUTE` relationships representing connections between hospitals.
- Shortest-path searching between hospitals.
- Incoming and outgoing transfer queries.
- Transfer and route counting.
- Date-based transfer queries.

## Web Application
The application was developed using:

- PHP
- HTML
- CSS
- JavaScript

It connects Oracle, MongoDB, and Neo4j and provides two main interfaces.

**Admin**
- View patients, ICU stays, equipment, and charges.
- Access medical information and transfer history.
- View hospital routes and global capacity.
- View charges per hospital.
- Manage hospital routes.

**Hospital Staff**
- Access information related to their hospital.
- View patients, ICU stays, and equipment.
- Manage daily charges and medical information.
- View hospital transfers and available routes.

## Testing and Performance Evaluation
The project includes:

- Successful operation testing.
- Invalid input testing.
- Database connection error testing.
- Transaction and data validation testing.
- MongoDB versus Oracle query benchmarking.
- Neo4j versus Oracle query benchmarking.
- Evaluation of database integration, accuracy, speed, and latency.

The performance tests were conducted locally using five executions per query, with average execution times calculated for comparison.

## Technologies Used
| Technology | Purpose |
|---|---|
| Oracle Database | Relational data and business logic |
| SQL | Database queries and operations |
| PL/SQL | Procedures, functions, triggers, and packages |
| MongoDB | Semi-structured medical information |
| Neo4j | Hospital graph relationships and route searching |
| PHP | Backend application development |
| HTML & CSS | Web interface |
| JavaScript | Frontend functionality |

## Academic Information
- **Project:** Academic Project
- **Course:** Database Programming
- **School:** School of Computing and Informatics
- **Semester:** Summer 2026

## License
© 2026 Mohammad Aldeghemat. All rights reserved.
