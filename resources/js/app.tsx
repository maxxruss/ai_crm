import React from 'react';
import { createRoot } from 'react-dom/client';
import { CssBaseline, ThemeProvider, createTheme } from '@mui/material';
import App from './components/App';

const theme = createTheme({
    palette: {
        mode: 'light',
        primary: { main: '#234e70' },
        background: { default: '#f5f7fa' },
    },
    shape: { borderRadius: 10 },
});

const root = document.getElementById('root');

if (!root) {
    throw new Error('React root element was not found.');
}

createRoot(root).render(
    <React.StrictMode>
        <ThemeProvider theme={theme}>
            <CssBaseline />
            <App />
        </ThemeProvider>
    </React.StrictMode>,
);
