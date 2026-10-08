import { createRouter, createWebHistory } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import AppLayout from "@/layouts/AppLayout.vue";

const routes = [
  {
    path: "/login",
    name: "login",
    component: () => import("@/views/auth/Login.vue"),
    meta: { guest: true },
  },

  {
    path: "/",
    component: AppLayout,
    meta: { auth: true },
    children: [
      { path: "", redirect: { name: "dashboard" } },

      {
        path: "dashboard",
        name: "dashboard",
        component: () => import("@/views/dashboard/Dashboard.vue"),
      },

      {
        path: "executive",
        name: "executive",
        component: () => import("@/views/dashboard/Executive.vue"),
        meta: { permission: "executive.view" },
      },

      {
        path: "reports",
        name: "reports",
        component: () => import("@/views/dashboard/Reports.vue"),
        meta: { permission: "executive.view" },
      },

      /* ---- Laboratoriya modullari ---- */
      {
        path: "samples",
        name: "samples",
        component: () => import("@/views/samples/SampleList.vue"),
        meta: { permission: "samples.view" },
      },
      {
        path: "samples/:id",
        name: "sample-detail",
        component: () => import("@/views/samples/SampleDetail.vue"),
        meta: { permission: "samples.view" },
      },
      {
        path: "samples/:id/protocol",
        name: "sample-protocol",
        component: () => import("@/views/samples/SampleProtocol.vue"),
        meta: { permission: "samples.view" },
      },
      {
        path: "test-objects",
        name: "test-objects",
        component: () => import("@/views/testobjects/TestObjectList.vue"),
        meta: { permission: "test_objects.view" },
      },
      {
        path: "test-objects/:id",
        name: "test-object-detail",
        component: () => import("@/views/testobjects/TestObjectDetail.vue"),
        meta: { permission: "test_objects.view" },
      },
      {
        path: "equipment",
        name: "equipment",
        component: () => import("@/views/equipment/EquipmentList.vue"),
        meta: { permission: "equipment.view" },
      },
      {
        path: "equipment/:id",
        name: "equipment-detail",
        component: () => import("@/views/equipment/EquipmentDetail.vue"),
        meta: { permission: "equipment.view" },
      },
      {
        path: "water-orders",
        name: "water-orders",
        component: () => import("@/views/water/WaterOrderList.vue"),
        meta: { permission: "water_orders.view" },
      },
      {
        path: "water-orders/:id",
        name: "water-order-detail",
        component: () => import("@/views/water/WaterOrderDetail.vue"),
        meta: { permission: "water_orders.view" },
      },
      {
        path: "monitoring-points",
        name: "monitoring-points",
        component: () => import("@/views/ResourcePage.vue"),
        meta: {
          permission: "monitoring_points.view",
          resource: "monitoring_points",
        },
      },
      {
        path: "ai-analysis",
        name: "ai-analysis",
        component: () => import("@/views/water/AiAnalysis.vue"),
        meta: { permission: "water_orders.view" },
      },

      /* ---- Kataloglar (universal CRUD) ---- */
      {
        path: "laboratories",
        name: "laboratories",
        component: () => import("@/views/ResourcePage.vue"),
        meta: { permission: "catalog.view", resource: "laboratories" },
      },
      {
        path: "laboratory-groups",
        name: "laboratory-groups",
        component: () => import("@/views/ResourcePage.vue"),
        meta: { permission: "catalog.view", resource: "laboratory_groups" },
      },
      {
        path: "elements",
        name: "elements",
        component: () => import("@/views/ResourcePage.vue"),
        meta: { permission: "catalog.view", resource: "elements" },
      },
      {
        path: "test-profiles",
        name: "test-profiles",
        component: () => import("@/views/ResourcePage.vue"),
        meta: { permission: "catalog.view", resource: "test_profiles" },
      },

      /* ---- Administratsiya ---- */
      {
        path: "users",
        name: "users",
        component: () => import("@/views/ResourcePage.vue"),
        meta: { permission: "users.view", resource: "users" },
      },
      {
        path: "roles",
        name: "roles",
        component: () => import("@/views/admin/RoleList.vue"),
        meta: { permission: "roles.view" },
      },
      {
        path: "menu-items",
        name: "menu-items",
        component: () => import("@/views/ResourcePage.vue"),
        meta: { permission: "menu.manage", resource: "menu_items" },
      },
      {
        path: "activity-logs",
        name: "activity-logs",
        component: () => import("@/views/admin/ActivityLog.vue"),
        meta: { permission: "logs.view" },
      },
      {
        path: "/trash",
        name: "trash",
        component: () => import("@/views/admin/Trash.vue"),
        meta: { permission: "trash.view" },
      },
      {
        path: "/manual",
        name: "manual",
        component: () => import("@/views/dashboard/Manual.vue"),
        meta: {
          /* permission yo'q — hammaga ochiq */
        },
      },
      {
        path: "profile",
        name: "profile",
        component: () => import("@/views/admin/Profile.vue"),
      },
      {
        path: "forbidden",
        name: "forbidden",
        component: () => import("@/views/Forbidden.vue"),
      },
      {
        path: ":pathMatch(.*)*",
        name: "not-found",
        component: () => import("@/views/NotFound.vue"),
      },
    ],
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: (to, from, saved) => saved ?? { top: 0 },
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();

  // Birinchi yuklanishda sessiyani tekshirish
  if (!auth.booted) {
    await auth.fetchUser();
  }

  if (to.meta.auth && !auth.isAuthenticated) {
    return {
      name: "login",
      query: to.fullPath !== "/" ? { redirect: to.fullPath } : {},
    };
  }

  if (to.meta.guest && auth.isAuthenticated) {
    return { name: "dashboard" };
  }
  // Ruxsat tekshiruvi — menyuda ko'rinmasa ham to'g'ridan-to'g'ri URL himoyalangan
  if (
    to.meta.permission &&
    auth.isAuthenticated &&
    !auth.can(to.meta.permission)
  ) {
    return { name: "forbidden" };
  }

  return true;
});
