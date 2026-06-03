# Master Files List - VIP System

This document provides a comprehensive list of all master files in the VIP System that handle CRUD (Create, Read, Update, Delete) operations for master data.

## Definition of Master Files

Master files are pages and API endpoints that manage core business entities (master data) such as:
- Products
- Customers
- Users/User Management
- Inventory Adjustments
- Units (if managed via UI)

---

## 📁 Master Files by Category

### 1. **Product Management**

#### Frontend Pages:
- **`capstone/pages/products_add.php`**
  - Purpose: Add new products to the system
  - Operations: CREATE
  - Validation: ✅ Comprehensive (product name, unit, prices, duplicates, price relationship)
  - SweetAlert: ✅ Yes
  
- **`capstone/pages/products_edit.php`**
  - Purpose: Edit existing product information
  - Operations: UPDATE
  - Validation: ✅ Comprehensive (product name, unit, prices, duplicates, price relationship)
  - SweetAlert: ✅ Yes (recently added)

#### Backend APIs:
- **`capstone/api/inventory_backend.php`**
  - Purpose: Handles product-related backend operations (discontinue, stock management)
  - Operations: UPDATE (discontinue status)

---

### 2. **Customer Management**

#### Frontend Pages:
- **`capstone/pages/users.php`**
  - Purpose: Manage customers (add, edit, view list)
  - Operations: CREATE, READ, UPDATE
  - Validation: ✅ Comprehensive (name, phone, email, aging days, credit limit, duplicates)
  - SweetAlert: ✅ Yes

#### Backend APIs:
- **`capstone/api/users_backend.php`**
  - Purpose: Handles customer CRUD operations
  - Operations: CREATE, UPDATE
  - Validation: ✅ Comprehensive (server-side validation for all fields)

---

### 3. **User Management (System Users)**

#### Frontend Pages:
- **`capstone/pages/user_management.php`**
  - Purpose: Manage system users (employees/admins)
  - Operations: CREATE, READ, UPDATE, DELETE (if implemented)
  - Validation: ⚠️ Needs review

#### Backend APIs:
- **`capstone/api/user_management_backend.php`**
  - Purpose: Handles system user CRUD operations
  - Operations: CREATE, UPDATE, DELETE (if implemented)
  - Validation: ⚠️ Needs review

---

### 4. **Inventory Management**

#### Frontend Pages:
- **`capstone/pages/manual_adjustment.php`**
  - Purpose: Manual inventory quantity adjustments
  - Operations: CREATE (adjustment records)
  - Validation: ✅ Comprehensive (product selection, adjustment value, reason, negative check)
  - SweetAlert: ✅ Yes

#### Backend APIs:
- **`capstone/api/manual_adjustment_backend.php`**
  - Purpose: Handles manual inventory adjustments
  - Operations: CREATE
  - Validation: ✅ Comprehensive (recently enhanced with full validation)

- **`capstone/api/inventory_backend.php`**
  - Purpose: Handles inventory-related operations
  - Operations: UPDATE (stock levels, product status)

---

### 5. **Production Management**

#### Frontend Pages:
- **`capstone/pages/production.php`**
  - Purpose: Manage production records
  - Operations: CREATE, READ
  - Validation: ⚠️ Needs review

#### Backend APIs:
- **`capstone/api/production_backend.php`**
  - Purpose: Handles production CRUD operations
  - Operations: CREATE, UPDATE
  - Validation: ⚠️ Needs review

---

### 6. **Order Management**

#### Frontend Pages:
- **`capstone/pages/orders.php`**
  - Purpose: Manage customer orders
  - Operations: CREATE, READ, UPDATE
  - Validation: ⚠️ Needs review

#### Backend APIs:
- **`capstone/api/orders_backend.php`**
  - Purpose: Handles order CRUD operations
  - Operations: CREATE, UPDATE
  - Validation: ⚠️ Needs review

---

### 7. **Sales Management**

#### Frontend Pages:
- **`capstone/pages/sales.php`**
  - Purpose: Process sales transactions
  - Operations: CREATE, READ
  - Validation: ⚠️ Needs review

#### Backend APIs:
- **`capstone/api/sales_backend.php`**
  - Purpose: Handles sales transactions
  - Operations: CREATE
  - Validation: ⚠️ Needs review

---

### 8. **Accounts Receivable Management**

#### Frontend Pages:
- **`capstone/pages/accounts_receivable.php`**
  - Purpose: Manage accounts receivable records
  - Operations: CREATE, READ, UPDATE
  - Validation: ⚠️ Needs review

#### Backend APIs:
- **`capstone/api/ar_backend.php`**
  - Purpose: Handles AR CRUD operations
  - Operations: CREATE, UPDATE
  - Validation: ⚠️ Needs review

---

### 9. **Delivery Management**

#### Frontend Pages:
- **`capstone/pages/delivery.php`**
  - Purpose: Manage delivery records
  - Operations: CREATE, READ, UPDATE
  - Validation: ⚠️ Needs review

#### Backend APIs:
- **`capstone/api/delivery_backend.php`**
  - Purpose: Handles delivery CRUD operations
  - Operations: CREATE, UPDATE
  - Validation: ⚠️ Needs review

---

## 📊 Summary Statistics

### Total Master Files: **18 files**

#### By Operation Type:
- **CREATE Operations**: 12 files
- **READ Operations**: 12 files
- **UPDATE Operations**: 10 files
- **DELETE Operations**: 1-2 files (if implemented)

#### By Validation Status:
- ✅ **Comprehensive Validation**: 5 files
  - `products_add.php`
  - `products_edit.php`
  - `users.php`
  - `users_backend.php`
  - `manual_adjustment_backend.php`
  
- ⚠️ **Needs Validation Review**: 13 files
  - `user_management.php`
  - `user_management_backend.php`
  - `production.php`
  - `production_backend.php`
  - `orders.php`
  - `orders_backend.php`
  - `sales.php`
  - `sales_backend.php`
  - `accounts_receivable.php`
  - `ar_backend.php`
  - `delivery.php`
  - `delivery_backend.php`
  - `inventory_backend.php`

---

## 🔍 Validation Checklist

For each master file, ensure the following validations are implemented:

### Required Field Validation
- [ ] All required fields are validated
- [ ] Empty/null values are rejected with clear error messages

### Data Type Validation
- [ ] Numeric fields accept only numbers
- [ ] String fields have length limits
- [ ] Date fields validate date format
- [ ] Email fields validate email format

### Business Logic Validation
- [ ] Duplicate prevention (unique constraints)
- [ ] Range validation (min/max values)
- [ ] Relationship validation (foreign keys)
- [ ] Negative value prevention (where applicable)

### Security Validation
- [ ] SQL injection prevention (prepared statements)
- [ ] XSS prevention (htmlspecialchars)
- [ ] CSRF protection (if applicable)
- [ ] Authorization checks (role-based access)

### User Experience
- [ ] Client-side validation for immediate feedback
- [ ] Server-side validation for security
- [ ] Clear error messages
- [ ] Success notifications (SweetAlert or similar)

---

## 📝 Notes

1. **Recent Enhancements**:
   - `products_add.php` - Added comprehensive validation and SweetAlert
   - `products_edit.php` - Added SweetAlert (Feb 2026)
   - `users.php` - Added comprehensive validation and SweetAlert
   - `manual_adjustment_backend.php` - Enhanced with comprehensive validation (Feb 2026)

2. **Files Not Listed**:
   - View-only pages (e.g., `sale_view.php`, `order_details.php`) are not considered master files
   - Report pages (e.g., `reports.php`) are not master files
   - Dashboard (`index.php`) is not a master file

3. **Recommendations**:
   - Review and add validation to all files marked with ⚠️
   - Implement consistent error handling across all master files
   - Add SweetAlert or similar notifications to all master files
   - Standardize validation patterns across the system

---

## 🔄 Last Updated
**Date**: February 17, 2026
**Updated By**: AI Assistant
**Version**: 1.0
