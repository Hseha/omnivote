import { createContext, useContext, useEffect, useState } from 'react';
import { THEME_PACKS } from './themePacks';

const THEME_KEY = 'omnivote-theme';
const PACK_KEY = 'omnivote-theme-pack';

const ThemeContext = createContext({
  theme: 'light',
  pack: 'classic',
  toggleTheme: () => {},
  selectPack: () => {},
});

export function ThemeProvider({ children }) {
  const [theme, setTheme] = useState(() => {
    try {
      const stored = localStorage.getItem(THEME_KEY);
      return stored === 'dark' || stored === 'light' ? stored : 'light';
    } catch {
      return 'light';
    }
  });

  const [pack, setPack] = useState(() => {
    try {
      const stored = localStorage.getItem(PACK_KEY);
      return THEME_PACKS.some((p) => p.id === stored) ? stored : 'classic';
    } catch {
      return 'classic';
    }
  });

  useEffect(() => {
    // data-theme decides light vs dark rendering; data-theme-pack decides the
    // palette (each pack has its own light and dark CSS token block).
    document.documentElement.dataset.theme = theme;
    document.documentElement.dataset.themePack = pack;
    try {
      localStorage.setItem(THEME_KEY, theme);
      localStorage.setItem(PACK_KEY, pack);
    } catch {
      // storage unavailable — theme still applies for this session
    }
  }, [theme, pack]);

  const toggleTheme = () => setTheme((prev) => (prev === 'dark' ? 'light' : 'dark'));

  const selectPack = (next) => {
    if (THEME_PACKS.some((p) => p.id === next)) setPack(next);
  };

  return (
    <ThemeContext.Provider value={{ theme, pack, toggleTheme, selectPack }}>
      {children}
    </ThemeContext.Provider>
  );
}

export function useTheme() {
  return useContext(ThemeContext);
}