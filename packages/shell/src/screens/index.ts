/**
 * Screens module barrel — wp-native-shell M6.
 *
 * Public surface:
 *   - AbilityList + adapter types (M6.1)
 *   - AbilityDetail + adapter types (M6.2)
 *   - SectionScreen + SectionPlaceholder (M6.3)
 *   - SectionDetailScreen (expo-router rebase — Slice D)
 */

// M6.1
export { AbilityList } from './ability-list';
export type {
	AbilityListAdapter,
	AbilityListItemProps,
	AbilityListProps,
} from './ability-list-types';

// M6.2
export { AbilityDetail } from './ability-detail';
export type {
	AbilityDetailAdapter,
	AbilityDetailProps,
	AbilityDetailRenderProps,
} from './ability-detail-types';

// M6.3
export { SectionScreen, SectionPlaceholder } from './section-screen';
export type { SectionScreenProps } from './section-screen';

// Slice D — detail route helper
export { SectionDetailScreen } from './section-detail-screen';
export type { SectionDetailScreenProps } from './section-detail-screen';
