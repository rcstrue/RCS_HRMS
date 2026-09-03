import { CheckCircle, ArrowRight, ShieldCheck } from 'lucide-react';

interface GoToESSProps {
  employee: { full_name: string | null; employee_code: number | string | null };
}

export function GoToESS({ employee }: GoToESSProps) {
  const name = employee.full_name || 'Employee';
  const code = employee.employee_code != null
    ? String(employee.employee_code).padStart(4, '0')
    : 'N/A';

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-emerald-500 via-green-500 to-teal-600">
      <div className="w-full max-w-sm mx-4 text-center animate-fade-in">
        {/* Green check circle */}
        <div className="mb-6 animate-bounce-in">
          <div className="w-24 h-24 mx-auto bg-white rounded-full flex items-center justify-center shadow-2xl">
            <CheckCircle className="w-14 h-14 text-green-500" />
          </div>
        </div>

        {/* Heading */}
        <h1 className="text-2xl font-bold text-white mb-2">
          Identity Verified!
        </h1>

        {/* Welcome text */}
        <p className="text-lg text-white/90 mb-4">
          Welcome, <span className="font-semibold">{name}</span>
        </p>

        {/* Employee code */}
        <div className="inline-flex items-center gap-2 bg-white/20 backdrop-blur-sm rounded-xl px-5 py-3 mb-8">
          <ShieldCheck className="w-5 h-5 text-white/80" />
          <div className="text-left">
            <p className="text-xs text-white/70 uppercase tracking-wide">Employee Code</p>
            <p className="text-lg font-bold text-white font-mono">EMP-{code}</p>
          </div>
        </div>

        {/* Large prominent button */}
        <button
          onClick={() => { window.location.hash = '#/ess'; }}
          className="w-full bg-white text-green-600 font-bold py-4 px-6 rounded-2xl shadow-xl hover:bg-green-50 transition-all active:scale-95 flex items-center justify-center gap-2 text-lg"
        >
          Go to Employee Portal (ESS)
          <ArrowRight className="w-5 h-5" />
        </button>

        {/* Small text below */}
        <p className="mt-4 text-white/60 text-sm">
          You'll set up a 4-digit PIN for quick login
        </p>
      </div>

      {/* Decorative circles */}
      <div className="absolute top-10 left-10 w-32 h-32 bg-white/10 rounded-full blur-3xl" />
      <div className="absolute bottom-10 right-10 w-40 h-40 bg-white/10 rounded-full blur-3xl" />
    </div>
  );
}
