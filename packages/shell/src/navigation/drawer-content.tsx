/**
 * DrawerContent — slot component for expo-router Drawer's `drawerContent`.
 *
 * Extracted from the deleted DrawerShell (Slice B, expo-router rebase).
 * Consumer mounts this inside their `app/(drawer)/_layout.tsx`:
 *
 *   <Drawer drawerContent={(props) => (
 *     <DrawerContent {...props} header={<Logo/>} footer={<SignOut/>} />
 *   )} />
 *
 * Uses `useNavigationConfig()` for sections, `useAuth()` for visibility
 * filtering, and `useTheme()` for colors/typography/spacing.
 */

import React, { useMemo } from 'react';
import { View, Text, StyleSheet, type TextStyle, type ViewStyle } from 'react-native';
import type { DrawerContentComponentProps } from '@react-navigation/drawer';

import { useAuth } from '../auth';
import type { AuthState } from '../auth';
import { useTheme } from '../theme';
import type { ThemeTokens } from '../theme';
import { useNavigationConfig } from './config';

// ─── Temporary stubs (Slice C removes these) ────────────────────────────────

/**
 * @deprecated Temporary stub so that the pre-rebase WPNativeApp (which
 * renders `<DrawerShell/>`) still compiles. Slice C rewrites WPNativeApp
 * to drop this usage. Do NOT use in new code.
 */
export interface DrawerShellProps {
  header?: React.ReactNode;
  footer?: React.ReactNode;
}

/**
 * @deprecated Stub — renders nothing. Slice C removes this.
 */
export function DrawerShell(_props: DrawerShellProps): React.ReactElement {
  return <></>;
}

// ─── Public types ────────────────────────────────────────────────────────────

export interface DrawerContentProps extends DrawerContentComponentProps {
  /** Content rendered above the section list in the drawer. */
  header?: React.ReactNode;
  /** Content rendered below the section list in the drawer. */
  footer?: React.ReactNode;
}

// ─── Themed styles ───────────────────────────────────────────────────────────

interface ThemedStyles {
  container: ViewStyle;
  sectionList: ViewStyle;
  item: ViewStyle;
  label: TextStyle;
  footer: ViewStyle;
}

function createThemedStyles(tokens: ThemeTokens): ThemedStyles {
  return StyleSheet.create<ThemedStyles>({
    container: {
      flex: 1,
      backgroundColor: tokens.colors.surface,
    },
    sectionList: {
      flex: 1,
      paddingTop: tokens.spacing.sm,
    },
    item: {
      paddingVertical: tokens.spacing.md,
      paddingHorizontal: tokens.spacing.lg,
    },
    label: {
      fontSize: tokens.typography.fontSizes.base,
      fontWeight: '600',
      color: tokens.colors.text,
      fontFamily: tokens.typography.fontFamily,
    },
    footer: {
      marginTop: 'auto',
      paddingBottom: tokens.spacing.lg,
    },
  });
}

// ─── Component ───────────────────────────────────────────────────────────────

/**
 * Custom drawer content renderer for expo-router consumers.
 *
 * Reads `useNavigationConfig()` to get the sections list, filters by
 * `visibleWhen` against the current auth state, and renders one tappable
 * item per visible section.
 */
export function DrawerContent({
  header,
  footer,
  ...drawerProps
}: DrawerContentProps): React.ReactElement {
  const { navigation: navConfig } = useNavigationConfig();
  const auth = useAuth();
  const tokens = useTheme();

  const authState: AuthState = {
    user: auth.user,
    isLoading: auth.isLoading,
    isAuthenticated: auth.isAuthenticated,
    sessionExpired: auth.sessionExpired,
  };

  const visibleSections = navConfig.sections.filter(
    (s) => !s.visibleWhen || s.visibleWhen(authState),
  );

  const styles = useMemo(() => createThemedStyles(tokens), [tokens]);

  return (
    <View style={styles.container}>
      {header ? <View>{header}</View> : null}

      <View style={styles.sectionList}>
        {visibleSections.map((section) => (
          <View key={section.id} style={styles.item}>
            <Text
              style={styles.label}
              onPress={() => {
                drawerProps.navigation.navigate(section.id);
              }}
            >
              {section.label}
            </Text>
          </View>
        ))}
      </View>

      {footer ? <View style={styles.footer}>{footer}</View> : null}
    </View>
  );
}
