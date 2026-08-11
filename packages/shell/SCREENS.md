# Generic Ability-Driven Screens

`wp-native-shell` provides generic React Native list and detail screens. Ability result shapes remain `unknown` inside the shell; consumer adapters perform type narrowing, input construction, and rendering.

## Ability List

```ts
interface AbilityListAdapter<TItem> {
  extractItems(result: unknown): TItem[];
  itemId(item: TItem): string | number;
  renderItem(props: { item: TItem; onPress(): void }): ReactElement;
  buildPageInput?(page: number, perPage: number): Record<string, unknown>;
  hasNextPage?(result: unknown, currentItems: TItem[]): boolean;
  perPage?: number;
  detailHref?(item: TItem): string;
}

interface AbilityListProps {
  ability: string;
  adapter: AbilityListAdapter<unknown>;
  detailAbility?: string;
  emptyState?: ReactNode;
  header?: ReactNode;
}
```

`<AbilityList>`:

- Calls `client.execute(ability, input)` on mount.
- Defaults to `{ page, per_page }` input and 20 items per page.
- Supports pull-to-refresh and infinite pagination.
- Uses `hasNextPage` when supplied; otherwise another page is available when the latest page contains at least `perPage` items.
- Renders consumer items through `renderItem`.
- Uses `detailHref(item)` for navigation when supplied; otherwise pushes the relative `./{itemId}` route.
- Displays theme-aware loading, error, retry, and empty states.

## Ability Detail

```ts
interface AbilityDetailAdapter<TEntity> {
  buildInput?(id: string | number): Record<string, unknown>;
  extractEntity(result: unknown): TEntity;
  render(props: { entity: TEntity }): ReactElement;
}

interface AbilityDetailProps<TEntity> {
  id: string | number;
  ability: string;
  adapter: AbilityDetailAdapter<TEntity>;
}
```

`<AbilityDetail>` defaults to `{ id }` input, executes the configured ability, narrows the result through `extractEntity`, and renders through the consumer adapter. It provides theme-aware loading, error, and retry states.

## Section Integration

`<SectionScreen sectionId="...">` looks up a `NavigationSection` from `NavigationConfigProvider` and applies this order:

1. Render the consumer `screen` when present.
2. Render `<AbilityList>` when both `ability` and `listAdapter` are present.
3. Render a themed placeholder.

`<SectionDetailScreen sectionId="..." id="...">` requires both `detailAbility` and `detailAdapter` on the section and delegates to `<AbilityDetail>`.

The shell does not mount a navigation stack. Consumers create expo-router list and `[id]` routes and render the section components from those files.

## Example

```tsx
const postListAdapter = {
  extractItems: (result: unknown) => (result as { posts: Post[] }).posts,
  itemId: (post: Post) => post.id,
  renderItem: ({ item, onPress }: { item: Post; onPress(): void }) => (
    <PostListItem post={item} onPress={onPress} />
  ),
};

const postDetailAdapter = {
  extractEntity: (result: unknown) => (result as { post: Post }).post,
  render: ({ entity }: { entity: Post }) => <PostDetail post={entity} />,
};

const navigation = {
  sections: [
    {
      id: 'feed',
      label: 'Feed',
      ability: 'wp/post.list',
      listAdapter: postListAdapter,
      detailAbility: 'wp/post.get',
      detailAdapter: postDetailAdapter,
    },
  ],
};
```

Ability names and response shapes in this example are consumer data. `wp-native-shell` does not hardcode them.

## Verification

From the repository root:

```bash
npm run typecheck
```

The exported source types are authoritative when this document and implementation differ.
