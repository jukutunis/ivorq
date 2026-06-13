import React from 'react';

interface AvatarProps {
  initials: string;
  bgColor?: string; // CSS variable name, e.g., 'primary-100' or 'warning-amber-bg'
  color?: string; // CSS variable name, e.g., 'primary-500' or 'warning-amber'
  style?: React.CSSProperties;
}

export default function Avatar({ initials, bgColor = 'primary-100', color = 'primary-500', style }: AvatarProps) {
  return (
    <div 
      style={{
        width: '32px',
        height: '32px',
        borderRadius: '50%',
        background: `var(--${bgColor})`,
        color: `var(--${color})`,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: '12px',
        fontWeight: 600,
        ...style
      }}
    >
      {initials}
    </div>
  );
}
