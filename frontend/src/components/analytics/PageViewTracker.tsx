import { useEffect, useState } from 'react';
import { useApp } from '../../context/AppContext';
import {
  ANALYTICS_CONSENT_EVENT,
  getAnalyticsConsent,
  trackPageView,
} from '../../services/webAnalyticsService';

export function PageViewTracker() {
  const { authLoading, currentView } = useApp();
  const [consent, setConsent] = useState(getAnalyticsConsent);

  useEffect(() => {
    const updateConsent = () => setConsent(getAnalyticsConsent());
    window.addEventListener(ANALYTICS_CONSENT_EVENT, updateConsent);
    return () => window.removeEventListener(ANALYTICS_CONSENT_EVENT, updateConsent);
  }, []);

  useEffect(() => {
    if (!authLoading && consent === 'accepted') void trackPageView(currentView);
  }, [authLoading, consent, currentView]);

  return null;
}
