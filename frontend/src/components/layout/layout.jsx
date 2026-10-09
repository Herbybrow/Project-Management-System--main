import { useAuth } from '../../context/AuthContext';
 
export default function Layout({ children }) {   const { user, logout } = useAuth(); 
 
  return ( 
    <div className="flex min-h-screen bg-gray-50"> 
      {/* Sidebar */} 
      <aside className="w-64 bg-slate-900 text-white flex flex-col justify-between p-4">         <div> 
          <h1 className="text-xl font-bold mb-8 text-blue-400">Project Portal</h1> 
          <nav className="space-y-2"> 
            <a href="#dashboard" className="block px-4 py-2 rounded hover:bg-slate-800">Dashboard</a> 
            <a href="#projects" className="block px-4 py-2 rounded hover:bg-slate-800">Projects</a> 
            <a href="#reviews" className="block px-4 py-2 rounded hover:bg-slate-800">Reviews</a> 
            <a href="#settings" className="block px-4 py-2 rounded hover:bg-slate-800">Settings</a> 
          </nav> 
        </div> 
        <div className="border-t border-slate-700 pt-4"> 
          <p className="text-sm text-gray-400">{user?.name}</p> 
          <span className="inline-block mt-1 px-2 py-0.5 bg-blue-900 text-blue-200 text-xs rounded font-semibold"> 
            {user?.role} 
          </span> 
          <button onClick={logout} className="mt-4 w-full bg-red-600 hover:bg-red-700 text-xs py-2 rounded"> 
            Logout 
          </button> 
        </div> 
      </aside> 
 
      {/* Main Content Area */} 
      <main className="flex-1 p-8 overflow-y-auto"> 
        {children} 
      </main> 
    </div> 
  ); 
} 
 
