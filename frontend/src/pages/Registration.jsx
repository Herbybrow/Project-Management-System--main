import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import api from '../lib/axios';

export default function Registration() {
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
  });
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  const handleChange = (e) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');

    if (formData.password !== formData.password_confirmation) {
      setError('Passwords do not match.');
      return;
    }

    setLoading(true);

    try {
      await api.post('/register', formData);
      alert('Account created successfully! Please sign in.');
      navigate('/login');
    } catch (err) {
      if (err.response?.status === 422) {
        const errors = err.response.data.errors;
        const firstKey = Object.keys(errors)[0];
        setError(errors[firstKey][0]);
      } else {
        setError(err.response?.data?.message || 'Registration failed. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4 py-12">
      <div className="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-100">
        
        {/* Header Banner */}
        <div 
          className="p-6 text-white text-center"
          style={{ backgroundColor: '#962c30' }}
        >
          <img
            src="/nssf-logo.png"
            alt="NSSF Logo"
            className="mx-auto mb-3 h-20 w-auto rounded-lg"
          />
          <h2 className="text-2xl font-bold tracking-wide">Create Account</h2>
          <p className="text-xs mt-1 uppercase tracking-widest font-bold" style={{ color: '#ffc20a' }}>
            Project Management System
          </p>
          <p className="text-[11px] mt-2 text-amber-200/80 font-medium">
            Personal and official email addresses are accepted.
          </p>
        </div>

        {/* Form Body */}
        <div className="p-8 space-y-5">
          {error && (
            <div 
              className="p-3 text-sm text-white rounded-lg font-medium shadow-sm"
              style={{ backgroundColor: '#068737' }}
            >
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                Full Name
              </label>
              <input
                type="text"
                name="name"
                required
                placeholder="First Name Last Name"
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:outline-none transition-all text-sm"
                value={formData.name}
                onChange={handleChange}
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                Email Address
              </label>
              <input
                type="email"
                name="email"
                required
                placeholder="user@example.com"
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:outline-none transition-all text-sm"
                value={formData.email}
                onChange={handleChange}
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                Password
              </label>
              <input
                type="password"
                name="password"
                required
                placeholder="••••••••"
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:outline-none transition-all text-sm"
                value={formData.password}
                onChange={handleChange}
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                Confirm Password
              </label>
              <input
                type="password"
                name="password_confirmation"
                required
                placeholder="••••••••"
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:outline-none transition-all text-sm"
                value={formData.password_confirmation}
                onChange={handleChange}
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full text-white font-bold py-3 rounded-lg shadow-md transition-all duration-200 mt-2 flex items-center justify-center hover:opacity-90"
              style={{ backgroundColor: '#962c30' }}
            >
              {loading ? 'Registering...' : 'Complete Registration'}
            </button>
          </form>

          {/* Login Link */}
          <div className="pt-4 text-center border-t border-gray-100">
            <span className="text-xs text-gray-500">Already registered? </span>
            <Link
              to="/login"
              className="text-xs font-bold hover:underline ml-1"
              style={{ color: '#962c30' }}
            >
              Sign In Here
            </Link>
          </div>
        </div>

        {/* Gold Accent Bar */}
        <div className="h-2 w-full" style={{ backgroundColor: '#ffc20a' }}></div>
      </div>
    </div>
  );
}
