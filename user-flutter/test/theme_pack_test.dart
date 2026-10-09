import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:omnivote/core/theme/app_theme.dart';
import 'package:omnivote/core/theme/app_tokens.dart';
import 'package:omnivote/core/theme/theme_pack.dart';

void main() {
  group('ThemePack.fromId', () {
    test('resolves every pack by its stable id', () {
      expect(ThemePack.fromId('classic'), ThemePack.classic);
      expect(ThemePack.fromId('ocean'), ThemePack.ocean);
      expect(ThemePack.fromId('sunset'), ThemePack.sunset);
    });

    test('falls back to classic for unknown or empty ids', () {
      expect(ThemePack.fromId(null), ThemePack.classic);
      expect(ThemePack.fromId(''), ThemePack.classic);
      expect(ThemePack.fromId('neon-chartreuse'), ThemePack.classic);
    });
  });

  group('theme packs', () {
    test('offers at least three distinct packs', () {
      expect(ThemePack.values.length, greaterThanOrEqualTo(3));
    });

    test('every pack provides a light and a dark palette', () {
      for (final pack in ThemePack.values) {
        expect(pack.light.brightness, Brightness.light,
            reason: '${pack.label} light palette');
        expect(pack.dark.brightness, Brightness.dark,
            reason: '${pack.label} dark palette');
      }
    });

    test('each pack carries its own accent color', () {
      final accents = ThemePack.values.map((p) => p.primary).toSet();
      expect(accents.length, ThemePack.values.length,
          reason: 'packs must differ in their accent so the selector is usable');
    });
  });

  group('AppTheme.fromPack', () {
    test('lightFor renders a light theme, darkFor a dark one', () {
      for (final pack in ThemePack.values) {
        expect(AppTheme.lightFor(pack).brightness, Brightness.light,
            reason: '${pack.label} light');
        expect(AppTheme.darkFor(pack).brightness, Brightness.dark,
            reason: '${pack.label} dark');
      }
    });

    test('classic getters match the classic pack', () {
      expect(AppTheme.lightTheme.brightness, Brightness.light);
      expect(AppTheme.darkTheme.brightness, Brightness.dark);
    });
  });

  group('AppTokens palette metadata', () {
    test('copyWith keeps brightness unless overridden', () {
      const token = AppTokens.dark;
      final tinted = token.copyWith(textPrimary: Colors.pink);
      expect(tinted.brightness, Brightness.dark);
      expect(tinted.textPrimary, Colors.pink);
    });

    test('lerp keeps the dominant side brightness', () {
      const a = AppTokens.light;
      const b = AppTokens.dark;
      expect(a.lerp(b, 0.2).brightness, Brightness.light);
      expect(a.lerp(b, 0.8).brightness, Brightness.dark);
    });
  });
}