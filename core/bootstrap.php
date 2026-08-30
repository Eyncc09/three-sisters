<?php
/**
 * Common bootstrap for module pages: session, DB, constants, permissions,
 * middleware guards, and Stage 2 service classes. Include this once at the
 * top of a page instead of the individual requires — pages built in Stage 1
 * (auth, dashboards) keep their own explicit requires and are unaffected.
 */
declare(strict_types=1);

require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/CategoryService.php';
require_once __DIR__ . '/BrandService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/ProductService.php';
require_once __DIR__ . '/CustomerService.php';
require_once __DIR__ . '/ResellerService.php';
require_once __DIR__ . '/OrderService.php';
require_once __DIR__ . '/PaymentService.php';
require_once __DIR__ . '/UploadHelper.php';
require_once __DIR__ . '/AnalyticsService.php';
require_once __DIR__ . '/PromotionService.php';
require_once __DIR__ . '/BasketAnalysisService.php';
require_once __DIR__ . '/DistributorService.php';
require_once __DIR__ . '/PurchaseOrderService.php';
require_once __DIR__ . '/ChatService.php';
