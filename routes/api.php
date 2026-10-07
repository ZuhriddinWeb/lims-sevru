<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ElementController;
use App\Http\Controllers\Api\ExecutiveController;
use App\Http\Controllers\Api\EquipmentCalibrationController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\EquipmentMaintenanceController;
use App\Http\Controllers\Api\LaboratoryController;
use App\Http\Controllers\Api\LaboratoryGroupController;
use App\Http\Controllers\Api\ManagementReportController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\MonitoringPointController;
use App\Http\Controllers\Api\MonitoringPointParameterController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SampleController;
use App\Http\Controllers\Api\TestObjectController;
use App\Http\Controllers\Api\TestProfileController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WaterOrderController;
use Illuminate\Support\Facades\Route;

// --- Ochiq marshrutlar ---
Route::post('/login', [AuthController::class, 'login']);

// --- Himoyalangan marshrutlar (Sanctum) ---
Route::middleware('auth:sanctum')->group(function () {

    // Autentifikatsiya / profil
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/preferences', [AuthController::class, 'updatePreferences']);

    // Eslatmalar (TZ 1.5)
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Rahbariyat hisobotlari (jadval ko'rinishida)
    Route::get('/management-reports', [ManagementReportController::class, 'index'])
        ->middleware('permission:executive.view|reports.view');
    Route::get('/management-reports/export', [ManagementReportController::class, 'export'])
        ->middleware('permission:executive.view|reports.export');

    // Rahbariyat paneli
    Route::get('/executive', [ExecutiveController::class, 'index'])
        ->middleware('permission:executive.view');

    // Menyu va dashboard
    Route::get('/menu', [MenuController::class, 'index']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Katalog ro'yxatlari (formalar uchun — oddiy o'qish)
    Route::get('/laboratories', [LaboratoryController::class, 'index']);
    Route::get('/laboratory-groups', [LaboratoryGroupController::class, 'index']);
    Route::get('/elements', [ElementController::class, 'index']);
    Route::get('/roles', [RoleController::class, 'index']);

    /* ================= USKUNALAR ================= */
    Route::middleware('permission:equipment.view')->group(function () {
        Route::get('/equipment', [EquipmentController::class, 'index']);
        Route::get('/equipment/{equipment}', [EquipmentController::class, 'show']);
        Route::get('/equipment/{equipment}/calibrations', [EquipmentCalibrationController::class, 'index']);
        Route::get('/equipment/{equipment}/maintenances', [EquipmentMaintenanceController::class, 'index']);
        Route::get('/equipment/{equipment}/history', [EquipmentController::class, 'history']);       // TZ 1.4
        Route::get('/equipment/{equipment}/documents', [DocumentController::class, 'equipmentIndex']); // TZ 1.9
    });

    // Uskuna hujjatlari — TZ 1.9
    Route::post('/equipment/{equipment}/documents', [DocumentController::class, 'equipmentStore'])
        ->middleware('permission:documents.create|equipment.update');
    Route::delete('/equipment/{equipment}/documents/{document}', [DocumentController::class, 'equipmentDestroy'])
        ->middleware('permission:documents.delete|equipment.delete');
    Route::post('/equipment', [EquipmentController::class, 'store'])->middleware('permission:equipment.create');
    Route::put('/equipment/{equipment}', [EquipmentController::class, 'update'])->middleware('permission:equipment.update');
    Route::patch('/equipment/{equipment}/status', [EquipmentController::class, 'changeStatus'])->middleware('permission:equipment.update');
    Route::patch('/equipment/{equipment}/block', [EquipmentController::class, 'toggleBlock'])->middleware('permission:equipment.update');
    Route::delete('/equipment/{equipment}', [EquipmentController::class, 'destroy'])->middleware('permission:equipment.delete');

    // Kalibrovka (nested)
    Route::post('/equipment/{equipment}/calibrations', [EquipmentCalibrationController::class, 'store'])->middleware('permission:equipment.update');
    Route::put('/equipment/{equipment}/calibrations/{calibration}', [EquipmentCalibrationController::class, 'update'])->middleware('permission:equipment.update');
    Route::delete('/equipment/{equipment}/calibrations/{calibration}', [EquipmentCalibrationController::class, 'destroy'])->middleware('permission:equipment.delete');

    // Texnik xizmat (nested)
    Route::post('/equipment/{equipment}/maintenances', [EquipmentMaintenanceController::class, 'store'])->middleware('permission:equipment.update');
    Route::put('/equipment/{equipment}/maintenances/{maintenance}', [EquipmentMaintenanceController::class, 'update'])->middleware('permission:equipment.update');
    Route::delete('/equipment/{equipment}/maintenances/{maintenance}', [EquipmentMaintenanceController::class, 'destroy'])->middleware('permission:equipment.delete');

    /* ================= SINOV OBYEKTLARI ================= */
    Route::middleware('permission:test_objects.view')->group(function () {
        Route::get('/test-objects', [TestObjectController::class, 'index']);
        Route::get('/test-objects/{testObject}', [TestObjectController::class, 'show']);
    });
    Route::get('/test-objects/{testObject}/documents', [DocumentController::class, 'testObjectIndex'])
        ->middleware('permission:test_objects.view');
    Route::post('/test-objects/{testObject}/documents', [DocumentController::class, 'testObjectStore'])
        ->middleware('permission:documents.create|test_objects.update');
    Route::delete('/test-objects/{testObject}/documents/{document}', [DocumentController::class, 'testObjectDestroy'])
        ->middleware('permission:documents.delete|test_objects.delete');

    Route::post('/test-objects', [TestObjectController::class, 'store'])->middleware('permission:test_objects.create');
    Route::put('/test-objects/{testObject}', [TestObjectController::class, 'update'])->middleware('permission:test_objects.update');
    Route::patch('/test-objects/{testObject}/dispose', [TestObjectController::class, 'dispose'])->middleware('permission:test_objects.update');
    Route::delete('/test-objects/{testObject}', [TestObjectController::class, 'destroy'])->middleware('permission:test_objects.delete');

    /* ================= PROBALAR ================= */
    Route::middleware('permission:samples.view')->group(function () {
        Route::get('/samples', [SampleController::class, 'index']);
        Route::get('/samples/{sample}', [SampleController::class, 'show']);
    });
    Route::post('/samples', [SampleController::class, 'store'])->middleware('permission:samples.create');
    Route::put('/samples/{sample}', [SampleController::class, 'update'])->middleware('permission:samples.update');
    Route::patch('/samples/{sample}/assign', [SampleController::class, 'assign'])->middleware('permission:samples.update');
    Route::patch('/samples/{sample}/archive', [SampleController::class, 'archive'])->middleware('permission:samples.update');
    // TZ — probani ishga olish
    Route::patch('/samples/{sample}/take', [SampleController::class, 'takeToWork'])
        ->middleware('permission:samples.result|samples.update');
    // Natija kiritish (alohida ruxsat)
    Route::put('/samples/{sample}/elements/{sampleElement}/result', [SampleController::class, 'saveResult'])->middleware('permission:samples.result');
    Route::delete('/samples/{sample}', [SampleController::class, 'destroy'])->middleware('permission:samples.delete');

    /* ================= SUV LABORATORIYASI ================= */
    Route::middleware('permission:water_orders.view')->group(function () {
        Route::get('/water-orders', [WaterOrderController::class, 'index']);
        Route::get('/water-orders/{waterOrder}', [WaterOrderController::class, 'show']);
    });
    Route::post('/water-orders', [WaterOrderController::class, 'store'])->middleware('permission:water_orders.create');
    Route::put('/water-orders/{waterOrder}', [WaterOrderController::class, 'update'])->middleware('permission:water_orders.update');
    Route::put('/water-orders/{waterOrder}/results', [WaterOrderController::class, 'saveResults'])->middleware('permission:water_orders.result');
    Route::patch('/water-orders/{waterOrder}/take', [WaterOrderController::class, 'takeToWork'])
        ->middleware('permission:water_orders.result|water_orders.update');
    Route::patch('/water-orders/{waterOrder}/archive', [WaterOrderController::class, 'archive'])
        ->middleware('permission:water_orders.update');
    Route::delete('/water-orders/{waterOrder}', [WaterOrderController::class, 'destroy'])->middleware('permission:water_orders.delete');

    // Monitoring nuqtalari
    Route::middleware('permission:monitoring_points.view')->get('/monitoring-points', [MonitoringPointController::class, 'index']);
    Route::post('/monitoring-points', [MonitoringPointController::class, 'store'])->middleware('permission:monitoring_points.create');
    Route::put('/monitoring-points/{monitoringPoint}', [MonitoringPointController::class, 'update'])->middleware('permission:monitoring_points.update');
    Route::delete('/monitoring-points/{monitoringPoint}', [MonitoringPointController::class, 'destroy'])->middleware('permission:monitoring_points.delete');

    // Monitoring nuqtasining standart ko'rsatkichlari va me'yorlari
    Route::get('/monitoring-points/{monitoringPoint}/parameters', [MonitoringPointParameterController::class, 'index'])
        ->middleware('permission:monitoring_points.view|water_orders.view');
    Route::put('/monitoring-points/{monitoringPoint}/parameters', [MonitoringPointParameterController::class, 'sync'])
        ->middleware('permission:monitoring_points.update');

    // Sinov profillari — TZ 2.10
    Route::get('/test-profiles', [TestProfileController::class, 'index']);
    Route::post('/test-profiles', [TestProfileController::class, 'store'])->middleware('permission:catalog.manage');
    Route::put('/test-profiles/{testProfile}', [TestProfileController::class, 'update'])->middleware('permission:catalog.manage');
    Route::delete('/test-profiles/{testProfile}', [TestProfileController::class, 'destroy'])->middleware('permission:catalog.manage');

    /* ================= HISOBOTLAR (TZ 2.13) ================= */
    Route::middleware('permission:reports.export')->group(function () {
        Route::get('/reports/samples', [ReportController::class, 'samples']);
        Route::get('/reports/equipment', [ReportController::class, 'equipment']);
        Route::get('/reports/test-objects', [ReportController::class, 'testObjects']);
        Route::get('/reports/water-orders', [ReportController::class, 'waterOrders']);
    });
    Route::get('/reports/sample-protocol/{sample}', [ReportController::class, 'sampleProtocol'])
        ->middleware('permission:reports.view|samples.view');

    // AI yordamchi
    Route::post('/ai/analyze', [AiAssistantController::class, 'analyze'])->middleware('permission:water_orders.view');

    /* ================= KATALOGLAR (CRUD) ================= */
    Route::post('/laboratories', [LaboratoryController::class, 'store'])->middleware('permission:catalog.manage');
    Route::get('/laboratories/{laboratory}', [LaboratoryController::class, 'show'])->middleware('permission:catalog.view');
    Route::put('/laboratories/{laboratory}', [LaboratoryController::class, 'update'])->middleware('permission:catalog.manage');
    Route::delete('/laboratories/{laboratory}', [LaboratoryController::class, 'destroy'])->middleware('permission:catalog.manage');

    Route::post('/laboratory-groups', [LaboratoryGroupController::class, 'store'])->middleware('permission:catalog.manage');
    Route::put('/laboratory-groups/{laboratoryGroup}', [LaboratoryGroupController::class, 'update'])->middleware('permission:catalog.manage');
    Route::delete('/laboratory-groups/{laboratoryGroup}', [LaboratoryGroupController::class, 'destroy'])->middleware('permission:catalog.manage');

    Route::post('/elements', [ElementController::class, 'store'])->middleware('permission:catalog.manage');
    Route::put('/elements/{element}', [ElementController::class, 'update'])->middleware('permission:catalog.manage');
    Route::delete('/elements/{element}', [ElementController::class, 'destroy'])->middleware('permission:catalog.manage');

    /* ================= ADMINISTRATSIYA ================= */
    // Foydalanuvchilar
    Route::middleware('permission:users.view')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
    });
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');

    // Rollar va ruxsatlar
    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:roles.view');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');

    // Menyu boshqaruvi
    Route::middleware('permission:menu.manage')->group(function () {
        Route::get('/menu-items', [MenuController::class, 'all']);
        Route::post('/menu-items', [MenuController::class, 'store']);
        Route::put('/menu-items/{menuItem}', [MenuController::class, 'update']);
        Route::delete('/menu-items/{menuItem}', [MenuController::class, 'destroy']);
    });

    // Audit jurnali
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:logs.view');
});
