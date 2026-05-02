/**
 * DrawerShell — config-driven drawer navigation for wp-native-shell.
 *
 * Matches SHELL.md M5.3 contract + M6.3 SectionScreen integration.
 *
 * Lineage: extrachill-app's `(drawer)/_layout.tsx` + `DrawerContent.tsx`,
 * generalized from hardcoded EC sections to config-driven NavigationSections.
 */

import React, { createContext, useContext, useMemo } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { createDrawerNavigator } from '@react-navigation/drawer';
import type { DrawerContentComponentProps } from '@react-navigation/drawer';

import { useAuth } from '../auth';
import type { AuthState } from '../auth';
import type { NavigationSection, WPNativeNavigationConfig } from './types';
import type { WPNativeBrowserHandoffConfig } from './handoff';
import { SectionScreen } from '../screens/section-screen';

// ─── Context ─────────────────────────────────────────────────────────────────

/**
 * Internal context shape consumed by navigation components and hooks.
 */
interface NavigationConfigContextValue {
  navigation: WPNativeNavigationConfig;
  browserHandoff?: WPNativeBrowserHandoffConfig | undefined;
}

const NavigationConfigContext =
  createContext<NavigationConfigContextValue | null>(null);

/**
 * Read the navigation config from context. Throws if used outside a
 * NavigationConfigProvider.
 */
export function useNavigationConfig(): NavigationConfigContextValue {
  const ctx = useContext(NavigationConfigContext);
  if (!ctx) {
    throw new Error(
      'useNavigationConfig must be used within a NavigationConfigProvider',
    );
  }
  return ctx;
}

/**
 * Props for NavigationConfigProvider.
 */
interface NavigationConfigProviderProps {
  navigation: WPNativeNavigationConfig;
  browserHandoff?: WPNativeBrowserHandoffConfig | undefined;
  children: React.ReactNode;
}

/**
 * Provides navigation and browser-handoff configuration to descendant
 * components and hooks (DrawerShell, useBrowserHandoff, etc.).
 */
export function NavigationConfigProvider({
  navigation,
  browserHandoff,
  children,
}: NavigationConfigProviderProps): React.ReactElement {
  const value = useMemo(
    () => ({ navigation, browserHandoff }),
    [navigation, browserHandoff],
  );

  return (
    <NavigationConfigContext.Provider value={value}>
      {children}
    </NavigationConfigContext.Provider>
  );
}

// ─── Drawer Navigator ────────────────────────────────────────────────────────

const Drawer = createDrawerNavigator();

// AbilityPlaceholderScreen removed in M6.3 — SectionScreen now owns
// the routing decision (consumer screen vs M6 list vs placeholder).

// ─── Drawer Content ──────────────────────────────────────────────────────────

/**
 * Custom drawer content renderer.
 *
 * Renders one item per visible NavigationSection. `header` and `footer`
 * render above/below the section list.
 */
function DrawerContentRenderer({
  header,
  footer,
  sections,
  authState,
  ...drawerProps
}: DrawerContentComponentProps & {
  header?: React.ReactNode;
  footer?: React.ReactNode;
  sections: ReadonlyArray<NavigationSection>;
  authState: AuthState;
}): React.ReactElement {
  const visibleSections = sections.filter(
    (s) => !s.visibleWhen || s.visibleWhen(authState),
  );

  return (
    <View style={contentStyles.container}>
      {header ? <View>{header}</View> : null}

      <View style={contentStyles.sectionList}>
        {visibleSections.map((section) => (
          <View key={section.id} style={contentStyles.item}>
            <Text
              style={contentStyles.label}
              onPress={() => {
                drawerProps.navigation.navigate(section.id);
              }}
            >
              {section.label}
            </Text>
          </View>
        ))}
      </View>

      {footer ? <View style={contentStyles.footer}>{footer}</View> : null}
    </View>
  );
}

const contentStyles = StyleSheet.create({
  container: {
    flex: 1,
  },
  sectionList: {
    flex: 1,
    paddingTop: 8,
  },
  item: {
    paddingVertical: 12,
    paddingHorizontal: 16,
  },
  label: {
    fontSize: 16,
    fontWeight: '600',
  },
  footer: {
    marginTop: 'auto',
    paddingBottom: 16,
  },
});

// ─── DrawerShell ─────────────────────────────────────────────────────────────

/**
 * Props for `<DrawerShell />`.
 */
export interface DrawerShellProps {
  /** Content rendered above the section list in the drawer. */
  header?: React.ReactNode;
  /** Content rendered below the section list in the drawer. */
  footer?: React.ReactNode;
}

/**
 * Config-driven drawer shell.
 *
 * Built on `@react-navigation/drawer`. Reads navigation config from
 * `NavigationConfigProvider` and auth state from `useAuth()`.
 *
 * Renders one drawer item per `NavigationSection` whose `visibleWhen`
 * returns `true` (or is absent). Sections with a `screen` render that
 * component; sections with only an `ability` render a placeholder.
 *
 * Usage:
 * ```tsx
 * <NavigationConfigProvider
 *   navigation={config.navigation}
 *   browserHandoff={config.browserHandoff}
 * >
 *   <DrawerShell
 *     header={<UserProfileHeader />}
 *     footer={<SignOutButton />}
 *   />
 * </NavigationConfigProvider>
 * ```
 */
export function DrawerShell({
  header,
  footer,
}: DrawerShellProps): React.ReactElement {
  const { navigation } = useNavigationConfig();
  const auth = useAuth();

  const authState: AuthState = {
    user: auth.user,
    isLoading: auth.isLoading,
    isAuthenticated: auth.isAuthenticated,
    sessionExpired: auth.sessionExpired,
  };

  const { sections } = navigation;

  return (
    <Drawer.Navigator
      screenOptions={{ headerShown: false, drawerType: 'front' }}
      drawerContent={(props: DrawerContentComponentProps) => (
        <DrawerContentRenderer
          {...props}
          header={header}
          footer={footer}
          sections={sections}
          authState={authState}
        />
      )}
    >
      {sections.map((section) => (
        <Drawer.Screen
          key={section.id}
          name={section.id}
          options={{ title: section.label }}
        >
          {() => <SectionScreen section={section} />}
        </Drawer.Screen>
      ))}
    </Drawer.Navigator>
  );
}
