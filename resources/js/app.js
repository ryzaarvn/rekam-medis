import * as healthcareBlockchain from './blockchain/healthcareRecord';

if (typeof window !== 'undefined') {
    window.HealthcareBlockchain = healthcareBlockchain;
}

export * from './blockchain/healthcareRecord';
