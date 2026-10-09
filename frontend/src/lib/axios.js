import axios from 'axios';
import { apiBaseUrl } from './apiConfig';
import { requestCache } from './requestCache';

const api = axios.create({
  baseURL: apiBaseUrl,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Interceptor to auto-attach Bearer Token
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('auth_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    if (config.data instanceof FormData) {
      delete config.headers['Content-Type'];
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// Tokens now expire (see config/sanctum.php) instead of lasting forever, so a
// session left open can start getting 401s mid-use. Without this, that showed
// up as each page's own generic "could not load" toast with no way back in —
// this sends the user to a clean re-login instead. A wrong-password 401 on
// the login attempt itself is a normal form error, not an expired session, so
// it's excluded here and left to the login page's own handling.
// Any write to a project or something under one (activities, requirements,
// documents, closure, plan review, etc.) can change what the cached project
// list would show, so a successful non-GET call anywhere under /projects
// clears it rather than every call site having to remember to.
api.interceptors.response.use(
  (response) => {
    const method = response.config?.method?.toLowerCase();
    if (method && !['get', 'head', 'options'].includes(method)) {
      requestCache.invalidate();
    }
    return response;
  },
  (error) => {
    const isLoginRequest = error.config?.url?.includes('/login');
    const onLoginPage = window.location.pathname === '/login';
    if (error.response?.status === 401 && !isLoginRequest && !onLoginPage) {
      requestCache.invalidate();
      localStorage.clear();
      window.location.assign('/login');
    }
    return Promise.reject(error);
  }
);

export default api;
