/**
 * SectionScreen — routing decision component for M6 generic screens.
 *
 * Matches SCREENS.md M6.3 contract.
 *
 * Decides what to render for a NavigationSection:
 *   1. Consumer-supplied `screen` always wins.
 *   2. Generic ability-driven list (with optional detail navigation)
 *      via SectionStack (Stack.Navigator wrapping AbilityList +
 *      AbilityDetail).
 *   3. Existing M5.3 placeholder fallback.
 *
 * Existing M5.3 sections (only `ability` set, no adapter) still render
 * the placeholder — no breakage.
 */

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';

import type { NavigationSection } from '../navigation/types';
import { useTheme } from '../theme';

import { AbilityList } from './ability-list';
import { AbilityDetail } from './ability-detail';

// ─── SectionPlaceholder ──────────────────────────────────────────────────────

/**
 * Themed placeholder for sections that have no screen, no adapter,
 * or only an ability name. Preserves M5.3 behavior.
 */
export function SectionPlaceholder({
  label,
}: {
  label: string;
}): React.ReactElement {
  const theme = useTheme();

  return (
    <View
      style={[
        placeholderStyles.container,
        { backgroundColor: theme.colors.background },
      ]}
    >
      <Text
        style={[
          placeholderStyles.text,
          {
            color: theme.colors.textMuted,
            fontFamily: theme.typography.fontFamily,
            fontSize: theme.typography.fontSizes.base,
          },
        ]}
      >
        {label}
      </Text>
    </View>
  );
}

const placeholderStyles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  text: {
    opacity: 0.6,
  },
});

// ─── SectionStack ────────────────────────────────────────────────────────────

/**
 * Route params for the detail screen inside SectionStack.
 */
type SectionStackParamList = {
  list: undefined;
  detail: { ability: string; id: string | number };
};

const Stack = createNativeStackNavigator<SectionStackParamList>();

/**
 * Stack navigator wrapping AbilityList (list route) and AbilityDetail
 * (detail route) for sections that have both listAdapter and
 * detailAdapter configured.
 */
function SectionStack({
  section,
}: {
  section: NavigationSection;
}): React.ReactElement {
  const theme = useTheme();

  // These are guaranteed non-null by the caller (SectionScreen checks
  // before rendering SectionStack), but TypeScript doesn't know that.
  const ability = section.ability!;
  const listAdapter = section.listAdapter!;
  const detailAbility = section.detailAbility!;
  const detailAdapter = section.detailAdapter!;

  return (
    <Stack.Navigator
      screenOptions={{
        headerShown: true,
        headerTintColor: theme.colors.primary,
        headerStyle: { backgroundColor: theme.colors.surface },
        headerTitleStyle: {
          fontFamily: theme.typography.fontFamily,
          fontSize: theme.typography.fontSizes.lg,
          color: theme.colors.text,
        },
        contentStyle: { backgroundColor: theme.colors.background },
      }}
    >
      <Stack.Screen
        name="list"
        options={{ title: section.label }}
      >
        {() => (
          <AbilityList
            ability={ability}
            adapter={listAdapter}
          />
        )}
      </Stack.Screen>

      <Stack.Screen
        name="detail"
        options={{ title: section.label }}
      >
        {({ route }) => (
          <AbilityDetail
            ability={detailAbility}
            adapter={detailAdapter}
            id={route.params.id}
          />
        )}
      </Stack.Screen>
    </Stack.Navigator>
  );
}

// ─── SectionScreen ───────────────────────────────────────────────────────────

/**
 * Props for SectionScreen.
 */
export interface SectionScreenProps {
  /** The navigation section to render. */
  section: NavigationSection;
}

/**
 * Routing decision component. Picks the right view for a NavigationSection:
 *
 * 1. Consumer-supplied `screen` always wins.
 * 2. Generic ability-driven list (with optional detail navigation via
 *    SectionStack) when `ability` + `listAdapter` are configured.
 * 3. Existing M5.3 placeholder fallback.
 */
export function SectionScreen({
  section,
}: SectionScreenProps): React.ReactElement {
  // 1. Consumer screen always wins.
  if (section.screen) {
    const ConsumerScreen = section.screen;
    return <ConsumerScreen />;
  }

  // 2. Generic ability-driven list (M6).
  if (section.ability && section.listAdapter) {
    if (section.detailAbility && section.detailAdapter) {
      return <SectionStack section={section} />;
    }

    return (
      <AbilityList
        ability={section.ability}
        adapter={section.listAdapter}
      />
    );
  }

  // 3. Placeholder (existing M5.3 behavior).
  return <SectionPlaceholder label={section.label} />;
}
