import {
  equipmentResource, testObjectResource, sampleResource,
  waterOrderResource, monitoringPointResource,
} from './lab'
import {
  laboratoryResource, laboratoryGroupResource, elementResource,
  userResource, menuItemResource, testProfileResource,
} from './admin'

// Marshrut meta.resource orqali topiladigan registr
export const RESOURCES = {
  equipment: equipmentResource,
  test_objects: testObjectResource,
  samples: sampleResource,
  water_orders: waterOrderResource,
  monitoring_points: monitoringPointResource,
  laboratories: laboratoryResource,
  laboratory_groups: laboratoryGroupResource,
  elements: elementResource,
  test_profiles: testProfileResource,
  users: userResource,
  menu_items: menuItemResource,
}

export function getResource(key) {
  return RESOURCES[key] ?? null
}
