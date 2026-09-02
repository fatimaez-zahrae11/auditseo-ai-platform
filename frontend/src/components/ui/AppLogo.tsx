import { useId } from 'react';

interface AppLogoProps {
  size?: number;
  className?: string;
  title?: string;
}

export function AppLogo({ size = 40, className = '', title = 'AuditSEO AI Platform' }: AppLogoProps) {
  const instanceId = useId().replace(/[^a-zA-Z0-9_-]/g, '');
  const baseId = `logo-base-${instanceId}`;
  const spectrumId = `logo-spectrum-${instanceId}`;
  const auraId = `logo-aura-${instanceId}`;

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 48 48"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={`shrink-0 ${className}`}
      role="img"
      aria-label={title}
    >
      <title>{title}</title>
      <defs>
        <linearGradient id={baseId} x1="7" y1="4" x2="41" y2="45" gradientUnits="userSpaceOnUse">
          <stop stopColor="#123F3A" />
          <stop offset="0.55" stopColor="#071D1B" />
          <stop offset="1" stopColor="#020C0F" />
        </linearGradient>
        <linearGradient id={spectrumId} x1="8" y1="40" x2="42" y2="7" gradientUnits="userSpaceOnUse">
          <stop stopColor="#22D3EE" />
          <stop offset="0.22" stopColor="#06D6A0" />
          <stop offset="0.46" stopColor="#3B82F6" />
          <stop offset="0.66" stopColor="#8B5CF6" />
          <stop offset="0.82" stopColor="#EC4899" />
          <stop offset="1" stopColor="#FB923C" />
        </linearGradient>
        <radialGradient id={auraId} cx="0" cy="0" r="1" gradientTransform="translate(24 22) rotate(90) scale(22)" gradientUnits="userSpaceOnUse">
          <stop stopColor="#06D6A0" stopOpacity="0.16" />
          <stop offset="0.6" stopColor="#8B5CF6" stopOpacity="0.07" />
          <stop offset="1" stopColor="#020C0F" stopOpacity="0" />
        </radialGradient>
      </defs>

      <rect x="1.5" y="1.5" width="45" height="45" rx="13.5" fill={`url(#${baseId})`} />
      <rect x="2.25" y="2.25" width="43.5" height="43.5" rx="12.75" fill={`url(#${auraId})`} stroke={`url(#${spectrumId})`} strokeWidth="1.5" />

      <circle cx="24" cy="24" r="11.25" stroke={`url(#${spectrumId})`} strokeWidth="2" />
      <path d="M13.25 24H34.75" stroke={`url(#${spectrumId})`} strokeWidth="1.6" strokeLinecap="round" />
      <path d="M15.7 18.25C18.1 19.55 21 20.25 24 20.25C27 20.25 29.9 19.55 32.3 18.25" stroke={`url(#${spectrumId})`} strokeWidth="1.25" strokeLinecap="round" opacity="0.9" />
      <path d="M15.7 29.75C18.1 28.45 21 27.75 24 27.75C27 27.75 29.9 28.45 32.3 29.75" stroke={`url(#${spectrumId})`} strokeWidth="1.25" strokeLinecap="round" opacity="0.9" />
      <path d="M24 12.75C20.9 15.65 19.25 19.55 19.25 24C19.25 28.45 20.9 32.35 24 35.25" stroke={`url(#${spectrumId})`} strokeWidth="1.45" strokeLinecap="round" />
      <path d="M24 12.75C27.1 15.65 28.75 19.55 28.75 24C28.75 28.45 27.1 32.35 24 35.25" stroke={`url(#${spectrumId})`} strokeWidth="1.45" strokeLinecap="round" />

      <path d="M17.25 27.25L22.25 22.5L27.1 25.1L31.4 19.7" stroke="#E6FBF6" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" opacity="0.9" />
      <circle cx="17.25" cy="27.25" r="1.55" fill="#22D3EE" stroke="#020C0F" strokeWidth="0.7" />
      <circle cx="22.25" cy="22.5" r="1.55" fill="#06D6A0" stroke="#020C0F" strokeWidth="0.7" />
      <circle cx="27.1" cy="25.1" r="1.55" fill="#EC4899" stroke="#020C0F" strokeWidth="0.7" />
      <circle cx="31.4" cy="19.7" r="1.55" fill="#FB923C" stroke="#020C0F" strokeWidth="0.7" />
    </svg>
  );
}
