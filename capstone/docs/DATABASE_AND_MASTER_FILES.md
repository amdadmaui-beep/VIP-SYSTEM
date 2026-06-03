# VIP System - Database & Master Files Reference

## Database Schema Overview

### Core Tables (from database.sql)
| Table | Purpose |
|-------|---------|
| **user** | System users (User_ID, user_name, password, Role_ID, is_active, status) |
| **products** | Product catalog (Product_ID, product_name, unit, wholesale_price, retail_price) |
| **stockin_inventory** | Inventory stock (Product_ID, quantity, date_in) |
| **adjustments** | Adjustment headers (adjustment_date, notes, created_by) |
| **adjustment_details** | Adjustment line items (Product_ID, Adjustment_ID, old_quantity, new_quantity, reason) |

### Roles (roles table)
| Role_ID | role_name | Access |
|---------|-----------|--------|
| 1 | owner | Full access, User Management |
| 2 | cashier | Sales, Production, etc. |
| 3 | delivery_rider | Rider Dashboard only |
| 4 | manager | Dashboard, restricted User Management |
| 5 | inventory_staff | Manual Adjustment, Production only |

### Orders & Delivery (orders_schema.sql, sales_delivery_schema.sql)
| Table | Purpose |
|-------|---------|
| **customers** | Customer master data |
| **orders** | Customer orders |
| **order_details** / **order_items** | Order line items |
| **delivery** | Delivery records (links to orders) |
| **delivery_detail** | Delivery line items |
| **sales** | Sales records |
| **sale_details** | Sale line items |

### Other Tables
| Table | Purpose |
|-------|---------|
| **productions** | Production records |
| **activity_logs** | Audit trail (rider actions) |
| **damage_types** | Damage reason lookup |
| **manual_adjustment** | Manual adjustment records (or use adjustments) |

### Schema Files
- `database/database.sql` - Base schema
- `database/orders_schema.sql` - Orders, customers
- `database/sales_delivery_schema.sql` - Sales, delivery
- `database/rider_dashboard_migrations.sql` - Activity logs, rider fields

---

## Master Files List

### Entry & Auth
| File | Purpose |
|------|---------|
| `login.php` | Login page, role-based redirect |
| `logout.php` | Logout |
| `includes/auth.php` | requireRole(), hasRole() |
| `includes/db.php` | Database connection |

### Dashboard & Role-Specific Views
| File | Purpose |
|------|---------|
| `index.php` | Main dashboard (Owner, Cashier, Manager) |
| `pages/rider_view.php` | Delivery Rider Dashboard |
| `pages/inventory_staff_view.php` | Inventory Staff home |
| `pages/cashier_view.php` | Cashier view |

### Product & Inventory
| File | Purpose |
|------|---------|
| `pages/products_add.php` | Add product |
| `pages/products_edit.php` | Edit product |
| `pages/inventory.php` | Inventory list, stock |
| `pages/manual_adjustment.php` | Manual inventory adjustment |
| `api/manual_adjustment_backend.php` | Adjustment POST handler |
| `api/inventory_backend.php` | Inventory operations |

### Production
| File | Purpose |
|------|---------|
| `pages/production.php` | Record production |
| `api/production_backend.php` | Production POST handler |

### Customers
| File | Purpose |
|------|---------|
| `pages/users.php` | Customer CRUD |
| `api/users_backend.php` | Customer backend |

### Orders
| File | Purpose |
|------|---------|
| `pages/orders.php` | Order management |
| `api/orders_backend.php` | Order CRUD, assign delivery |
| `api/get_order_details.php` | Order details JSON |

### Sales
| File | Purpose |
|------|---------|
| `pages/sales.php` | Sales, record payment |
| `pages/sale_view.php` | Sale detail view |
| `api/sales_backend.php` | Sales backend |
| `api/get_sale_details.php` | Sale details JSON |
| `api/get_delivery_details.php` | Delivery details JSON |

### Delivery
| File | Purpose |
|------|---------|
| `pages/delivery.php` | Delivery management |
| `api/delivery_backend.php` | Delivery CRUD, status update |
| `api/rider_dashboard_backend.php` | Rider: confirm delivery, collections |

### User Management
| File | Purpose |
|------|---------|
| `pages/user_management.php` | System users CRUD |
| `api/user_management_backend.php` | User backend |

### Accounting & Reports
| File | Purpose |
|------|---------|
| `pages/accounts_receivable.php` | AR management |
| `api/ar_backend.php` | AR backend |
| `pages/reports.php` | Reports |

### API Helpers
| File | Purpose |
|------|---------|
| `api/dashboard_stats.php` | Dashboard stats |
| `api/dashboard_charts.php` | Charts data |
| `api/get_customer_credit.php` | Customer credit check |
| `api/export_sales.php` | Export sales |
| `api/export_production_today.php` | Export production |

### Database Migrations
| File | Purpose |
|------|---------|
| `database/add_manager_role.php` | Add manager role |
| `database/add_inventory_staff_role.php` | Add inventory staff role |
| `database/fix_delivery_rider_role.php` | Fix rider Role_ID |
| `database/run_rider_migrations.php` | Rider dashboard migrations |

---

## Delivery Rider Redirect Fix

If the delivery rider is sent to manual adjustment instead of the rider view:

1. **Run the fix script:**
   ```bash
   cd capstone
   php database/fix_delivery_rider_role.php
   ```

2. **Or fix manually in SQL:**
   ```sql
   -- Ensure delivery_rider role exists
   INSERT INTO roles (Role_ID, role_name, role_description) VALUES (3, 'delivery_rider', 'Delivery Rider') ON DUPLICATE KEY UPDATE role_name='delivery_rider';
   
   -- Fix your rider user (replace 'rider1' with actual username)
   UPDATE user SET Role_ID = 3 WHERE user_name = 'rider1';
   ```

3. The login now checks both `Role_ID = 3` and `role_name` containing "rider" for the redirect.
