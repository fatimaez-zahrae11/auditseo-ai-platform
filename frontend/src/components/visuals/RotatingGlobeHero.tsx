import { useEffect, useMemo, useRef, useState } from 'react';
import { Canvas, useFrame, useThree } from '@react-three/fiber';
import * as THREE from 'three';

interface RotatingGlobeHeroProps {
  className?: string;
}

interface AnimatedSceneProps {
  reducedMotion: boolean;
}

interface LightTrailProps extends AnimatedSceneProps {
  controlPoints: [number, number, number][];
  phase: number;
}

const ORANGE = '#FF8A00';
const SECONDARY_ORANGE = '#F97316';

function useReducedMotion() {
  const [reducedMotion, setReducedMotion] = useState(false);

  useEffect(() => {
    const query = window.matchMedia('(prefers-reduced-motion: reduce)');
    const updatePreference = () => setReducedMotion(query.matches);
    updatePreference();
    query.addEventListener('change', updatePreference);
    return () => query.removeEventListener('change', updatePreference);
  }, []);

  return reducedMotion;
}

function useDesktopGlobe() {
  const [showGlobe, setShowGlobe] = useState(false);

  useEffect(() => {
    const query = window.matchMedia('(min-width: 768px)');
    const updateVisibility = () => setShowGlobe(query.matches);
    updateVisibility();
    query.addEventListener('change', updateVisibility);
    return () => query.removeEventListener('change', updateVisibility);
  }, []);

  return showGlobe;
}

function LightTrail({ controlPoints, phase, reducedMotion }: LightTrailProps) {
  const geometry = useMemo(() => {
    const curve = new THREE.CatmullRomCurve3(controlPoints.map((point) => new THREE.Vector3(...point)));
    return new THREE.BufferGeometry().setFromPoints(curve.getPoints(144));
  }, [controlPoints]);
  const material = useMemo(() => new THREE.LineBasicMaterial({
    color: Math.round(phase / 0.9) % 2 === 0 ? ORANGE : SECONDARY_ORANGE,
    transparent: true,
    opacity: 0.14,
    blending: THREE.AdditiveBlending,
    depthWrite: false,
    toneMapped: false,
  }), [phase]);
  const line = useMemo(() => new THREE.Line(geometry, material), [geometry, material]);

  useEffect(() => () => {
    geometry.dispose();
    material.dispose();
  }, [geometry, material]);

  useFrame(({ clock }) => {
    if (reducedMotion) return;
    material.opacity = 0.11 + (Math.sin(clock.elapsedTime * 0.62 + phase) + 1) * 0.035;
  });

  return <primitive object={line} />;
}

function OrbitalRings({ reducedMotion }: AnimatedSceneProps) {
  const ringsRef = useRef<THREE.Group>(null);

  useFrame((_, delta) => {
    if (reducedMotion || !ringsRef.current) return;
    ringsRef.current.rotation.y += delta * 0.042;
    ringsRef.current.rotation.z -= delta * 0.022;
  });

  return (
    <group ref={ringsRef} rotation={[0.18, -0.12, 0.08]}>
      <mesh rotation={[0.3, 0.08, -0.36]}>
        <torusGeometry args={[2.85, 0.012, 8, 192]} />
        <meshBasicMaterial color={ORANGE} transparent opacity={0.42} blending={THREE.AdditiveBlending} depthWrite={false} toneMapped={false} />
      </mesh>
      <mesh rotation={[1.04, 0.34, 0.48]}>
        <torusGeometry args={[3.12, 0.009, 8, 192]} />
        <meshBasicMaterial color={SECONDARY_ORANGE} transparent opacity={0.26} blending={THREE.AdditiveBlending} depthWrite={false} toneMapped={false} />
      </mesh>
      <mesh rotation={[0.58, 1.08, -0.72]}>
        <torusGeometry args={[2.62, 0.007, 8, 192]} />
        <meshBasicMaterial color={ORANGE} transparent opacity={0.2} blending={THREE.AdditiveBlending} depthWrite={false} toneMapped={false} />
      </mesh>
    </group>
  );
}

function AbstractGlobe({ reducedMotion }: AnimatedSceneProps) {
  const globeRef = useRef<THREE.Group>(null);
  const positions = useMemo(() => {
    const pointCount = 920;
    const radius = 2.2;
    const values = new Float32Array(pointCount * 3);
    const goldenAngle = Math.PI * (3 - Math.sqrt(5));

    for (let index = 0; index < pointCount; index += 1) {
      const y = 1 - (index / (pointCount - 1)) * 2;
      const horizontalRadius = Math.sqrt(1 - y * y);
      const theta = goldenAngle * index;
      values[index * 3] = Math.cos(theta) * horizontalRadius * radius;
      values[index * 3 + 1] = y * radius;
      values[index * 3 + 2] = Math.sin(theta) * horizontalRadius * radius;
    }

    return values;
  }, []);

  useFrame((_, delta) => {
    if (reducedMotion || !globeRef.current) return;
    globeRef.current.rotation.y += delta * 0.085;
    globeRef.current.rotation.z += delta * 0.007;
  });

  return (
    <group ref={globeRef} rotation={[-0.18, -0.55, 0.08]}>
      <mesh>
        <sphereGeometry args={[2.2, 44, 30]} />
        <meshBasicMaterial color={ORANGE} wireframe transparent opacity={0.2} blending={THREE.AdditiveBlending} depthWrite={false} toneMapped={false} />
      </mesh>
      <points>
        <bufferGeometry>
          <bufferAttribute attach="attributes-position" args={[positions, 3]} />
        </bufferGeometry>
        <pointsMaterial color={ORANGE} size={0.027} sizeAttenuation transparent opacity={0.48} blending={THREE.AdditiveBlending} depthWrite={false} toneMapped={false} />
      </points>
      <mesh scale={1.08}>
        <sphereGeometry args={[2.2, 32, 24]} />
        <meshBasicMaterial color={SECONDARY_ORANGE} transparent opacity={0.035} side={THREE.BackSide} blending={THREE.AdditiveBlending} depthWrite={false} toneMapped={false} />
      </mesh>
    </group>
  );
}

function GlobeScene({ reducedMotion }: AnimatedSceneProps) {
  const trailGroupRef = useRef<THREE.Group>(null);
  const viewportWidth = useThree((state) => state.viewport.width);
  const globeX = Math.min(3.3, Math.max(1.55, viewportWidth * 0.27));
  const trails = useMemo<[number, number, number][][]>(() => {
    const leftEdge = (-viewportWidth / 2) - globeX - 0.35;
    const rightEdge = (viewportWidth / 2) - globeX + 0.35;

    return [
      [[1.4, 1.25, -0.2], [-0.35, 2.05, -0.55], [leftEdge * 0.5, 1.5, -0.85], [leftEdge, 0.35, -1.15]],
      [[0.8, 0.65, 0.75], [-1.1, 0.35, 0.3], [leftEdge * 0.62, -0.05, -0.35], [leftEdge, -0.85, -0.85]],
      [[1.05, -1.18, -0.35], [-0.55, -1.8, -0.55], [leftEdge * 0.55, -1.45, -0.95], [leftEdge, -0.2, -1.35]],
      [[1.7, 0.1, -0.75], [0.1, 1.05, -1], [leftEdge * 0.58, 0.72, -1.35], [leftEdge, 1.5, -1.65]],
      [[0.35, -0.35, 1.05], [-1.4, -0.75, 0.55], [leftEdge * 0.66, -0.2, -0.15], [leftEdge, 0.15, -0.7]],
      [[-1.45, 1.35, -0.95], [0.15, 2.05, -1.05], [rightEdge * 0.55, 1.55, -1.2], [rightEdge, 0.7, -1.35]],
      [[-0.9, -1.45, -0.65], [0.6, -2.05, -0.85], [rightEdge * 0.62, -1.3, -1.1], [rightEdge, -0.5, -1.4]],
    ];
  }, [globeX, viewportWidth]);

  useFrame(({ clock }) => {
    if (reducedMotion || !trailGroupRef.current) return;
    trailGroupRef.current.rotation.z = Math.sin(clock.elapsedTime * 0.19) * 0.025;
    trailGroupRef.current.position.y = Math.sin(clock.elapsedTime * 0.25) * 0.045;
  });

  return (
    <group position={[globeX, 0, 0]}>
      <group ref={trailGroupRef}>
        {trails.map((points, index) => <LightTrail key={index} controlPoints={points} phase={index * 0.9} reducedMotion={reducedMotion} />)}
      </group>
      <OrbitalRings reducedMotion={reducedMotion} />
      <AbstractGlobe reducedMotion={reducedMotion} />
      <pointLight color={ORANGE} intensity={2.2} distance={7} decay={2} position={[1.6, 0.6, 2.8]} />
    </group>
  );
}

export function RotatingGlobeHero({ className = '' }: RotatingGlobeHeroProps) {
  const reducedMotion = useReducedMotion();
  const showGlobe = useDesktopGlobe();

  return (
    <div className={`pointer-events-none h-full w-full overflow-hidden ${className}`} aria-hidden="true">
      {showGlobe ? <Canvas
        camera={{ position: [0, 0, 9], fov: 42 }}
        dpr={[1, 1.5]}
        frameloop={reducedMotion ? 'demand' : 'always'}
        gl={{ alpha: true, antialias: true, powerPreference: 'high-performance' }}
        onCreated={({ gl }) => gl.setClearColor('#000000', 0)}
        style={{ pointerEvents: 'none' }}
      >
        <GlobeScene reducedMotion={reducedMotion} />
      </Canvas> : null}
    </div>
  );
}
