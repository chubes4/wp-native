# wp-native-shell — Generic Ability-Driven Screens (M6)

This document is the **authoritative contract** for the M6 generic screen primitives. Implementations in `packages/shell/src/screens/` must match this spec exactly.

The premise: a `NavigationSection` with `ability: 'wp/post.list'` (and no `screen`) should render automatically — fetching the data, rendering a list, supporting refresh/pagination/detail-navigation. The shell can't know each ability's schema at compile time, so consumers plug in **adapters** that bridge the ability's wire format to the shell's render model.

If you're a minion implementing M6: this file is your source of truth.

## The two screens

```
NavigationSection.ability                NavigationSection.detailAbility
   │                                        │
   ▼                                        ▼
<AbilityList/>     ── tap an item ──▶  <AbilityDetail/>
   │                                        │
   ├── extracts items via adapter           ├── extracts entity via adapter
   ├── handles pagination                   ├── handles loading / error
   ├── handles refresh                      └── consumer renderer renders entity
   ├── consumer renderer renders each item
   └── tap navigates to detail
```

## Updated `NavigationSection`

The existing `NavigationSection` (M5.3) gets two new optional fields. These are additive — sections with only `ability` and no other fields still work as drawer placeholders; sections that opt into M6 generic screens add `listAdapter` (and optionally `detailAbility` + `detailAdapter`).

```ts
export interface NavigationSection {
  id: string;
  label: string;
  ability?: string;
  screen?: ComponentType;
  visibleWhen?: (auth: AuthState) => boolean;

  // ── M6 additions (all optional, all only meaningful when `ability` is set) ──

  /** Adapts the ability's result into list items. */
  listAdapter?: AbilityListAdapter<unknown>;

  /** If set, list-item taps navigate here with the item's id. */
  detailAbility?: string;

  /** Adapts the detail ability's result into a renderable entity. */
  detailAdapter?: AbilityDetailAdapter<unknown>;
}
```

When a section has `ability` + `listAdapter`, M6 renders an `<AbilityList/>` instead of a placeholder. When a section also has `detailAbility` + `detailAdapter`, list-item taps push an `<AbilityDetail/>` route.

When `screen` is set, it still wins (consumer-supplied screens always override the generic).

---

## M6.1 — `<AbilityList/>`

### `AbilityListAdapter`

Adapter type. Tells the list screen how to extract items + pagination metadata from the ability's result.

```ts
export interface AbilityListAdapter<TItem> {
  /**
   * Extract list items from the ability result.
   * Called on every page; concatenated across pages.
   */
  extractItems: (result: unknown) => TItem[];

  /**
   * Stable identifier for an item (used as React key + detail route param).
   */
  itemId: (item: TItem) => string | number;

  /**
   * Render one list item. Receives the item plus theme + navigation helpers.
   */
  renderItem: (props: AbilityListItemProps<TItem>) => ReactElement;

  /**
   * Build the input for `client.execute(ability, input)` for a given page.
   * Default: `{ page, per_page }` — works for any ability following WP REST
   * pagination conventions. Override for abilities with different param names.
   */
  buildPageInput?: (page: number, perPage: number) => Record<string, unknown>;

  /**
   * Determine whether more pages are available. Default: returns true if
   * the page returned at least `perPage` items, false otherwise. Override
   * for abilities that return total counts in the result.
   */
  hasNextPage?: (result: unknown, currentItems: TItem[]) => boolean;

  /**
   * Page size. Default: 20.
   */
  perPage?: number;
}

export interface AbilityListItemProps<TItem> {
  item: TItem;
  /** Tap handler. If detail ability is configured, this navigates. */
  onPress: () => void;
}
```

### `<AbilityList/>` props

```ts
export interface AbilityListProps {
  /** Ability name to call. */
  ability: string;
  /** Adapter for this ability's result shape. */
  adapter: AbilityListAdapter<unknown>;
  /** Detail ability name. If set, items become tappable. */
  detailAbility?: string;
  /** Optional empty state. */
  emptyState?: ReactNode;
  /** Optional header rendered above the list. */
  header?: ReactNode;
}

export const AbilityList: FC<AbilityListProps>;
```

### Behavior

- On mount: call `client.execute(ability, adapter.buildPageInput?.(1, perPage) ?? { page: 1, per_page: perPage })`.
- Extract items via `adapter.extractItems(result)`.
- Render each via `adapter.renderItem({ item, onPress })`. The `onPress` handler:
  - If `detailAbility` is set: navigate to the detail route with `{ ability: detailAbility, id: adapter.itemId(item) }`.
  - Otherwise: no-op (or expose to consumer via a future `onItemPress` prop).
- **Pull-to-refresh**: re-fetches page 1, replaces the list.
- **Infinite scroll**: when scrolled to bottom and `adapter.hasNextPage` is true, fetch next page.
- **Loading state**: full-screen spinner on first load (uses theme `colors.primary`).
- **Error state**: full-screen error message with a Retry button (uses theme `colors.error`). Error message comes from `ApiError.message`.
- **Empty state**: rendered when first page returns 0 items. Uses `emptyState` prop or a default \"No items.\" message.

### Files

```
packages/shell/src/screens/
├── ability-list.tsx           AbilityList component
├── ability-list-types.ts      AbilityListAdapter, AbilityListProps, AbilityListItemProps
```

---

## M6.2 — `<AbilityDetail/>`

### `AbilityDetailAdapter`

```ts
export interface AbilityDetailAdapter<TEntity> {
  /**
   * Extract the entity from the ability result.
   */
  extractEntity: (result: unknown) => TEntity;

  /**
   * Build the input for `client.execute(ability, input)` given an item id.
   * Default: `{ id }`.
   */
  buildInput?: (id: string | number) => Record<string, unknown>;

  /**
   * Render the entity. Receives entity + theme.
   */
  render: (props: AbilityDetailRenderProps<TEntity>) => ReactElement;
}

export interface AbilityDetailRenderProps<TEntity> {
  entity: TEntity;
}
```

### `<AbilityDetail/>` props

```ts
export interface AbilityDetailProps {
  /** Ability name. */
  ability: string;
  /** Identifier passed to `adapter.buildInput(id)`. */
  id: string | number;
  /** Adapter. */
  adapter: AbilityDetailAdapter<unknown>;
}

export const AbilityDetail: FC<AbilityDetailProps>;
```

### Behavior

- On mount + when `id` changes: call `client.execute(ability, adapter.buildInput?.(id) ?? { id })`.
- Extract entity, render via `adapter.render({ entity })`.
- Loading state: theme spinner.
- Error state: theme-styled error with Retry.

### Files

```
packages/shell/src/screens/
├── ability-detail.tsx         AbilityDetail component
├── ability-detail-types.ts    AbilityDetailAdapter, AbilityDetailProps
```

---

## M6.3 — Section integration

The drawer's section rendering logic gets updated to handle M6 sections:

```ts
function renderSection(section: NavigationSection): ReactElement {
  // 1. Consumer screen always wins
  if (section.screen) {
    return <section.screen />;
  }

  // 2. Generic ability-driven list (M6)
  if (section.ability && section.listAdapter) {
    const props: AbilityListProps = {
      ability: section.ability,
      adapter: section.listAdapter,
    };
    if (section.detailAbility) {
      props.detailAbility = section.detailAbility;
    }
    return <AbilityList {...props} />;
  }

  // 3. Placeholder (existing M5.3 behavior)
  return <SectionPlaceholder label={section.label} />;
}
```

Plus: introduce a stack navigator inside each section so list → detail navigation works. The stack is per-section (each section owns its own list+detail pair).

### Files

```
packages/shell/src/screens/
├── section-screen.tsx         SectionScreen (decides list vs detail vs consumer)
├── index.ts                   barrel
```

The `DrawerShell` (M5.3) gets one minimal change: where it currently renders the `screen` component or placeholder, it now calls `<SectionScreen section={section} />`. SectionScreen owns the routing.

---

## Consumer example

A consumer's config grows from M5 (`ability` only, placeholder rendered) to M6 (full generic list + detail):

```ts
import type { WPNativeConfig } from 'wp-native-shell';
import { ArtistListItem, ArtistDetailView } from './components/artists';

export const config: WPNativeConfig = {
  // ... api, brand, tokenStorage, theme

  navigation: {
    sections: [
      {
        id: 'feed',
        label: 'Feed',
        ability: 'wp/post.list',
        listAdapter: {
          extractItems: (r) => (r as { posts: Post[] }).posts,
          itemId: (post) => post.id,
          renderItem: ({ item, onPress }) => (
            <PostListItem post={item} onPress={onPress} />
          ),
        },
        detailAbility: 'wp/post.get',
        detailAdapter: {
          extractEntity: (r) => (r as { post: Post }).post,
          render: ({ entity }) => <PostDetailView post={entity} />,
        },
      },
      {
        id: 'artists',
        label: 'Artists',
        ability: 'extrachill/list-artists',
        listAdapter: {
          extractItems: (r) => (r as { artists: Artist[] }).artists,
          itemId: (artist) => artist.id,
          renderItem: ({ item, onPress }) => (
            <ArtistListItem artist={item} onPress={onPress} />
          ),
          // Custom pagination shape:
          buildPageInput: (page, perPage) => ({ offset: (page - 1) * perPage, limit: perPage }),
          hasNextPage: (result, items) => items.length < (result as { total: number }).total,
        },
        // No detail ability → items aren't tappable.
      },
    ],
  },
};
```

The shell renders both sections from data only. The consumer writes only the `PostListItem` / `PostDetailView` / `ArtistListItem` components.

---

## Implementation rules for minions

1. **TypeScript strict mode**. No `any`. Catch variables typed `unknown`.
2. **No platform-specific imports outside RN core**. Use `react-native` primitives (FlatList, ScrollView, ActivityIndicator). No external libs unless already a peer dep.
3. **Theme integration**. All visual elements (loaders, errors, empty states) use `useTheme()` for colors / typography / spacing. No hardcoded values.
4. **Consumer escape hatch**. Every prop / behavior the consumer might want to customize should have a sensible default that doesn't require explicit override.
5. **`unknown` everywhere ability results flow**. The shell never knows ability shapes — adapters do the type narrowing. Cast inside adapter calls, not in shell internals.
6. **Verify**: `npx tsc -b` from repo root must exit 0.

## Definition of done (per slice)

Each minion's PR is done when:
- [ ] All files listed in their slice's "Files" section exist
- [ ] All exported types match this contract verbatim
- [ ] All exported component signatures match this contract
- [ ] `npx tsc -b` exits 0
- [ ] No `any` in the slice's source files
- [ ] Theme tokens used for all visual elements
- [ ] PR opened on `chubes4/wp-native`, NOT merged
- [ ] `<@1493317298151489577>` mentioned in the final message
