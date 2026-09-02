import React from 'react';

interface ScoreCircleProps {
  score: number;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  showLabel?: boolean;
  label?: string;
}

export const ScoreCircle: React.FC<ScoreCircleProps> = ({
  score,
  size = 'lg',
  showLabel = true,
  label = 'Global SEO Score',
}) => {
  const getGrade = (val: number) => {
    if (val >= 90) return { grade: 'A+', color: 'text-[var(--color-text)]', stroke: 'var(--color-primary)', bg: 'bg-[var(--color-primary)] text-[var(--color-on-primary)]' };
    if (val >= 80) return { grade: 'A', color: 'text-[var(--color-text)]', stroke: 'var(--color-surface-strong)', bg: 'bg-[var(--color-surface-strong)] text-[var(--color-text)]' };
    if (val >= 70) return { grade: 'B', color: 'text-[var(--color-text)]', stroke: 'var(--color-soft)', bg: 'bg-[var(--color-soft)] text-[var(--color-on-soft)]' };
    if (val >= 55) return { grade: 'C', color: 'text-[var(--color-warning-text)]', stroke: 'var(--color-warning-border)', bg: 'bg-[var(--color-warning-bg)] text-[var(--color-warning-text)]' };
    if (val >= 40) return { grade: 'D', color: 'text-[var(--color-warning-text)]', stroke: 'var(--color-warning-text)', bg: 'bg-[var(--color-warning-bg)] text-[var(--color-warning-text)]' };
    return { grade: 'F', color: 'text-[var(--color-danger-text)]', stroke: 'var(--color-danger-border)', bg: 'bg-[var(--color-danger-bg)] text-[var(--color-danger-text)]' };
  };

  const { grade, color, stroke, bg } = getGrade(score);

  const dimensions = {
    sm: { size: 64, strokeWidth: 5, fontSize: 'text-base', gradeSize: 'text-xs' },
    md: { size: 96, strokeWidth: 7, fontSize: 'text-2xl', gradeSize: 'text-xs' },
    lg: { size: 140, strokeWidth: 10, fontSize: 'text-4xl', gradeSize: 'text-sm' },
    xl: { size: 190, strokeWidth: 12, fontSize: 'text-5xl', gradeSize: 'text-base' },
  }[size];

  const radius = (dimensions.size - dimensions.strokeWidth * 2) / 2;
  const circumference = 2 * Math.PI * radius;
  const strokeDashoffset = circumference - (Math.min(100, Math.max(0, score)) / 100) * circumference;

  return (
    <div id="score-circle-container" className="flex flex-col items-center justify-center">
      <div className="relative flex items-center justify-center">
        <svg
          width={dimensions.size}
          height={dimensions.size}
          className="transform -rotate-90"
        >
          {/* Background circle */}
          <circle
            cx={dimensions.size / 2}
            cy={dimensions.size / 2}
            r={radius}
            stroke="var(--color-border)"
            strokeWidth={dimensions.strokeWidth}
            fill="transparent"
          />
          {/* Progress circle */}
          <circle
            cx={dimensions.size / 2}
            cy={dimensions.size / 2}
            r={radius}
            stroke={stroke}
            strokeWidth={dimensions.strokeWidth}
            strokeDasharray={circumference}
            strokeDashoffset={strokeDashoffset}
            strokeLinecap="round"
            fill="transparent"
            className="transition-all duration-1000 ease-out"
          />
        </svg>

        <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
          <span className={`font-bold tracking-tight ${color} ${dimensions.fontSize}`}>
            {score}
          </span>
          <span className={`font-semibold px-1.5 py-0.2 rounded-full ${bg} ${dimensions.gradeSize}`}>
            Grade {grade}
          </span>
        </div>
      </div>

      {showLabel && (
        <span className="mt-2 text-xs font-semibold text-[var(--color-muted)] tracking-wide uppercase">
          {label}
        </span>
      )}
    </div>
  );
};
