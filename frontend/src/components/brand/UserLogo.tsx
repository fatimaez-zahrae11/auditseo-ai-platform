import { useId } from 'react';

interface UserLogoProps {
  size?: number;
  className?: string;
}

export function UserLogo({ size = 36, className = '' }: UserLogoProps) {
  const instanceId = useId().replace(/[^a-zA-Z0-9_-]/g, '');
  const accentId = `user-logo-accent-${instanceId}`;
  const surfaceId = `user-logo-surface-${instanceId}`;
  const glowId = `user-logo-glow-${instanceId}`;

  return (
    <svg
      aria-hidden="true"
      focusable="false"
      width={size}
      height={size}
      viewBox="0 0 48 48"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={`shrink-0 ${className}`}
    >
      <defs>
        <linearGradient id={accentId} x1="9" y1="39" x2="39" y2="8" gradientUnits="userSpaceOnUse">
          <stop stopColor="#FF8A00" />
          <stop offset="1" stopColor="#F97316" />
        </linearGradient>
        <radialGradient id={surfaceId} cx="0" cy="0" r="1" gradientTransform="translate(25 20) rotate(90) scale(25)" gradientUnits="userSpaceOnUse">
          <stop stopColor="#17201D" />
          <stop offset="0.62" stopColor="#071416" />
          <stop offset="1" stopColor="#020C0F" />
        </radialGradient>
        <filter id={glowId} x="-40%" y="-40%" width="180%" height="180%" colorInterpolationFilters="sRGB">
          <feGaussianBlur stdDeviation="1.5" result="blur" />
          <feMerge>
            <feMergeNode in="blur" />
            <feMergeNode in="SourceGraphic" />
          </feMerge>
        </filter>
      </defs>

      <rect x="2" y="2" width="44" height="44" rx="13" fill={`url(#${surfaceId})`} />
      <rect x="2.75" y="2.75" width="42.5" height="42.5" rx="12.25" stroke="#FF8A00" strokeOpacity="0.35" strokeWidth="1.5" />

      <g stroke={`url(#${accentId})`} strokeLinecap="round" strokeLinejoin="round">
        <path d="M10.5 17V13.5C10.5 11.84 11.84 10.5 13.5 10.5H17" strokeWidth="2" />
        <path d="M31 10.5H34.5C36.16 10.5 37.5 11.84 37.5 13.5V17" strokeWidth="2" />
        <path d="M37.5 31V34.5C37.5 36.16 36.16 37.5 34.5 37.5H31" strokeWidth="2" />
        <path d="M17 37.5H13.5C11.84 37.5 10.5 36.16 10.5 34.5V31" strokeWidth="2" />

        <circle cx="24" cy="23" r="9.5" strokeWidth="1.7" opacity="0.92" />
        <path d="M14.75 23H33.25" strokeWidth="1.25" opacity="0.72" />
        <path d="M17 17.5C19 18.6 21.45 19.2 24 19.2C26.55 19.2 29 18.6 31 17.5" strokeWidth="1.1" opacity="0.62" />
        <path d="M24 13.5C21.55 16 20.25 19.25 20.25 23C20.25 26.55 21.42 29.62 23.65 32.08" strokeWidth="1.2" opacity="0.72" />
        <path d="M24 13.5C26.45 16 27.75 19.25 27.75 23C27.75 24.15 27.63 25.24 27.38 26.28" strokeWidth="1.2" opacity="0.72" />
      </g>

      <path d="M18 28.5L21.25 25.6L24.5 27.25L30.75 21" stroke="#FFF7ED" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      <path d="M27.55 21H30.75V24.2" stroke={`url(#${accentId})`} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" filter={`url(#${glowId})`} />
      <circle cx="18" cy="28.5" r="1.35" fill="#FF8A00" />
      <circle cx="21.25" cy="25.6" r="1.35" fill="#FB8500" />
      <circle cx="24.5" cy="27.25" r="1.35" fill="#F97316" />
    </svg>
  );
}
